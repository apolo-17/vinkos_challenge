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

class LoadVisits extends Command
{
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
        // Ruta a la carpeta 'visitas'
        $path = storage_path('app/visitas');

        // Obtener todos los archivos .txt en la carpeta
        $files = glob($path . '/*.txt');

        // Mensaje para indicar cuántos archivos se encontraron
        $this->info(count($files) . ' archivo(s) encontrado(s) para procesar.');

        foreach ($files as $file) {
            // Leer el contenido del archivo línea por línea
            // Leer el contenido del archivo línea por línea
            $fileContents = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            // Saltar la primera línea (encabezados)
            if (!empty($fileContents)) {
                array_shift($fileContents); // Elimina la primera línea
            }

            foreach ($fileContents as $linea) {
                // Validar el layout del archivo
                $validationResult = $this->validarLayout($linea);

                if ($validationResult === true) {
                    // Procesar datos si el layout es correcto
                    $this->procesarDatos($linea);
                } else {
                    // Registrar en la tabla de errores si el layout no es válido
                    $this->registrarError($linea, "Layout no válido: $validationResult");
                }
            }

            // Mover el archivo a una carpeta de respaldo y eliminarlo de la original
            $backupPath = storage_path('app/visitas/bckp');
            if (!file_exists($backupPath)) {
                mkdir($backupPath, 0775, true); // Crear el directorio si no existe
            }

            // Mover archivo a la carpeta de respaldo
            rename($file, $backupPath . '/' . basename($file));
        }

        $this->info('Procesamiento de archivos completado.');
    }


    private function validarLayout($linea)
    {
        // Divide la línea en columnas usando la coma como delimitador
        $columns = str_getcsv($linea);

        // Define el número esperado de columnas
        $expectedColumnCount = 15;

        // Valida el número de columnas
        if (count($columns) !== $expectedColumnCount) {
            return "se esperaban $expectedColumnCount columnas, pero se encontraron " . count($columns);
        }

        // Puedes agregar más validaciones aquí según tus requisitos

        return true; // Si todo es correcto, retorna true
    }


    private function procesarDatos($linea)
    {
        $data = $this->formatData($linea);

        $fechaOpen = $data->get('fecha_open');

        // Procesar datos para la tabla visitante
        $visitante = Visitor::firstOrNew(['email' => $data->get('email')]);
        if (!$visitante->exists) {
            // Primer registro de visita para este email
            $visitante->fecha_primera_visita = $fechaOpen;
            $visitante->fecha_ultima_visita = $fechaOpen;
            $visitante->visitas_totales = $fechaOpen ? $data->get('opens') : 0;
            $visitante->visitas_anio_actual = $fechaOpen ? $data->get('opens') : 0;
            $visitante->visitas_mes_actual = $fechaOpen ? $data->get('opens') : 0;
        } else {

            if ($fechaOpen < $visitante->fecha_primera_visita) {
                $visitante->fecha_primera_visita = $fechaOpen;
            }
            // Comparar para asignar la fecha de última visita si es más reciente
            if ($fechaOpen > $visitante->fecha_ultima_visita) {
                $visitante->fecha_ultima_visita = $fechaOpen;
            }

            // Incrementar el conteo de visitas
            $visitante->visitas_totales += $data->get('opens');
            $visitante->visitas_anio_actual += $data->get('opens');
            $visitante->visitas_mes_actual += ($fechaOpen->year === now()->year && $fechaOpen->month === now()->month) ? 1 : 0;
        }
        $visitante->save();

        // Procesar datos para la tabla estadística
        Statistic::create($data->toArray());
    }

    private function formatData(string $linea): Collection
    {
        $columnas = str_getcsv($linea);
        $columnas[1] = isset($columnas[1]) || !empty($columnas[1]) ? null : $columnas[1];
        $columnas[6] = is_numeric(trim($columnas[6])) ?  (int) trim($columnas[6]) : 0;
        $columnas[7] = is_numeric(trim($columnas[7])) ?  (int) trim($columnas[7]) : 0;
        $columnas[9] = is_numeric(trim($columnas[9])) ?  (int) trim($columnas[9]) : 0;;
        $columnas[10] = is_numeric(trim($columnas[10])) ?  (int) trim($columnas[10]) : 0;;

        if (!$this->validarEmail(trim($columnas[0]))) {
            $this->registrarError($linea, "Email no válido: " . $columnas[0]);
        }

        if (!$this->validarIp(trim($columnas[12]))) {
            $this->registrarError($linea, "IP no válida: " . $columnas[12]);
            $columnas[12] = 0;
        }


        $camposFechas = [
            'fecha_envio' => 4,
            'fecha_open' => 5,
            'fecha_click' => 8
        ];

        $keys = [
            'email',
            'jyv',
            'badmail',
            'baja',
            'fecha_envio',
            'fecha_open',
            'opens',
            'opens_virales',
            'fecha_click',
            'clicks',
            'clicks_virales',
            'links',
            'ips',
            'navegadores',
            'plataformas'
        ];

        foreach ($camposFechas as $key => $value) {

            if (isset($columnas[$value]) && !empty($columnas[$value])) {
                try {
                    $columnas[$value] = Carbon::createFromFormat('d/m/Y H:i', trim($columnas[$value]));
                } catch (\Exception $e) {
                    // Si la fecha no es válida, dejarla como null y registramos el error.
                    $this->registrarError($linea, "Fecha no válida: " . trim($columnas[$value]));
                    $columnas[$value] = null;
                }
            } else {
                $columnas[$value] = null;
            }
        }

        $data = collect(array_combine($keys, $columnas));

        return $data;
    }

    private function registrarError($linea, $descripcionError)
    {
        // Llenamos todos los campos necesarios para la tabla 'errors'
        DB::table('errors')->insert([
            'line_content' => $linea,
            'error_description' => $descripcionError,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function validarEmail($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function validarIp($ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}
