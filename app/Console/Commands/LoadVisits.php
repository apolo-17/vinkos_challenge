<?php

namespace App\Console\Commands;

use App\Models\Error;
use App\Models\Statistic;
use App\Models\Visitor;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use phpseclib3\Net\SFTP;

class LoadVisits extends Command
{
    private $fileName = null;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:load-visits';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Carga datos de visitas desde archivos de texto y los guarda en la base de datos.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Conexión SFTP y descarga de archivos
        $sftpHost = 'localhost';
        $sftpUser = 'foo';
        $sftpPassword = 'pass';
        $sftpPort = 2222;
        $remotePath = '/archivosVisitas'; // Ruta a la carpeta `archivosVisitas`
        $localPath = storage_path('app/visitas'); // Ruta local para guardar los archivos descargados

        $sftp = new SFTP($sftpHost, $sftpPort);

        if (!$sftp->login($sftpUser, $sftpPassword)) {
            $this->error('Error al conectar con el servidor SFTP.');
            return;
        }

        // Descargar archivos desde el servidor SFTP
        $this->info('Descargando archivos desde SFTP...');
        $remoteFiles = $sftp->nlist($remotePath);

        // Filtrar solo archivos .txt
        $txtFiles = array_filter($remoteFiles, function ($file) {
            return pathinfo($file, PATHINFO_EXTENSION) === 'txt';
        });

        if (empty($txtFiles)) {
            $this->info('No se encontraron archivos .txt en el servidor.');
            return;
        }

        foreach ($txtFiles as $remoteFile) {
            $localFilePath = $localPath . '/' . $remoteFile;
            $sftp->get($remotePath . '/' . $remoteFile, $localFilePath);
            $this->info("Archivo descargado: $localFilePath");
        }

        // Procesar los archivos descargados
        $files = glob($localPath . '/*.txt');
        $this->info(count($files) . ' archivo(s) encontrado(s) para procesar.');

        foreach ($files as $file) {
            $this->processFile($file);
            $this->backupFile($file, storage_path('app/visitas/bckp'));
        }

        // Transferir archivos procesados al servidor SFTP
        $this->info('Transferiendo archivos procesados al servidor SFTP...');
        foreach ($files as $file) {
            $remoteBackupPath = '/archivosVisitas/bckp/' . basename($file);
            $sftp->put($remoteBackupPath, $file, SFTP::SOURCE_LOCAL_FILE);
            $this->info("Archivo transferido: $remoteBackupPath");

            // Eliminar el archivo del servidor SFTP después de procesarlo
            $sftp->delete($remotePath . '/' . basename($file));
            $this->info("Archivo eliminado del servidor SFTP: $remotePath/" . basename($file));
        }

        $this->info('Procesamiento de archivos completado.');
    }



    private function processFile(string $filePath)
    {
        $fileContents = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($fileContents)) return;

        // Extraer el nombre del archivo
        $this->fileName = basename($filePath);

        array_shift($fileContents); // Elimina encabezado

        foreach ($fileContents as $line) {
            $this->processLine($line);
        }
    }

    private function processLine(string $line)
    {
        $isValidLayout = $this->validarLayout($line);
        if ($isValidLayout !== true) {
            $this->registrarError($line, "Layout no válido: $isValidLayout");
            return;
        }

        $data = $this->formatData($line);
        if (!$data) return;  // Si hubo errores en el formato, se registraron y se omite la línea

        $this->saveVisitorData($data);

        $data->put('file_name', $this->fileName);

        Statistic::create($data->toArray());
    }

    private function saveVisitorData(Collection $data)
    {
        $fechaOpen = $data->get('fecha_open');
        $visitante = Visitor::firstOrNew(['email' => $data->get('email')]);

        if (!$visitante->exists) {
            $visitante->fill([
                'fecha_primera_visita' => $fechaOpen,
                'fecha_ultima_visita' => $fechaOpen,
                'visitas_totales' => $data->get('opens'),
                'visitas_anio_actual' => $data->get('opens'),
                'visitas_mes_actual' => $this->isCurrentMonth($fechaOpen) ? $data->get('opens') : 0,
            ]);
        } else {
            $visitante->fecha_primera_visita = min($fechaOpen, $visitante->fecha_primera_visita);
            $visitante->fecha_ultima_visita = max($fechaOpen, $visitante->fecha_ultima_visita);
            $visitante->increment('visitas_totales', $data->get('opens'));
            $visitante->increment('visitas_anio_actual', $data->get('opens'));

            if ($this->isCurrentMonth($fechaOpen)) {
                $visitante->increment('visitas_mes_actual', $data->get('opens'));
            }
        }

        $visitante->save();
    }

    private function isCurrentMonth($date)
    {
        return $date && $date->year === now()->year && $date->month === now()->month;
    }

    private function validarLayout($line)
    {
        $columns = str_getcsv($line);
        $expectedColumnCount = 15;

        return count($columns) === $expectedColumnCount
            ? true
            : "Se esperaban $expectedColumnCount columnas, pero se encontraron " . count($columns);
    }

    private function formatData(string $line): ?Collection
    {
        $columns = str_getcsv($line);
        $keys = ['email', 'jyv', 'badmail', 'baja', 'fecha_envio', 'fecha_open', 'opens', 'opens_virales', 'fecha_click', 'clicks', 'clicks_virales', 'links', 'ips', 'navegadores', 'plataformas'];

        $fields = array_combine($keys, $columns);

        // Validar y formatear campos
        if (!$this->validateAndFormatFields($fields, $line)) return null;

        return collect($fields);
    }


    private function validateAndFormatFields(array &$fields, string $line): bool
    {
        // Validar email
        if (!$this->validarEmail($fields['email'])) {
            $this->registrarError($line, "Email no válido: " . $fields['email']);
            return false;
        }

        // Validar IP
        if (!$this->validarIp($fields['ips'])) {
            $this->registrarError($line, "IP no válida: " . $fields['ips']);
            $fields['ips'] = '0.0.0.0'; // Asignar null si es inválida
        }

        // Validar y formatear fechas
        $fechaCampos = ['fecha_envio', 'fecha_open', 'fecha_click'];

        foreach ($fechaCampos as $campo) {
            if (!empty($fields[$campo])) {
                try {
                    $fields[$campo] = Carbon::createFromFormat('d/m/Y H:i', trim($fields[$campo]));
                } catch (\Exception $e) {
                    $this->registrarError($line, "Fecha no válida: " . $fields[$campo]);
                    $fields[$campo] = null;
                }
            } else {
                // Si está vacío, asignar como null sin registrar error
                $fields[$campo] = null;
            }
        }

        // Validar campos numéricos
        $fields['opens'] = is_numeric($fields['opens']) ? (int) $fields['opens'] : 0;
        $fields['opens_virales'] = is_numeric($fields['opens_virales']) ? (int) $fields['opens_virales'] : 0;
        $fields['clicks'] = is_numeric($fields['clicks']) ? (int) $fields['clicks'] : 0;
        $fields['clicks_virales'] = is_numeric($fields['clicks_virales']) ? (int) $fields['clicks_virales'] : 0;

        return true;
    }


    private function parseDate($date, $line)
    {
        if (empty($date)) return null;

        try {
            return Carbon::createFromFormat('d/m/Y H:i', trim($date));
        } catch (\Exception $e) {
            $this->registrarError($line, "Fecha no válida: " . trim($date));
            return null;
        }
    }

    private function backupFile(string $filePath, string $backupPath)
    {
        if (!file_exists($backupPath)) {
            mkdir($backupPath, 0775, true);
        }

        rename($filePath, $backupPath . '/' . basename($filePath));
    }

    private function registrarError($line, $descripcionError)
    {
        DB::table('errors')->insert([
            'line_content' => $line,
            'error_description' => $descripcionError,
            'file_name' => $this->fileName,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function validarEmail($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function validarIp($ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}
