<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';

/**
 * Listados exportables y sus filtros.
 *
 * Exportar todo sirve poco: lo que se pide es «los terneros nacidos este mes»,
 * «las vacas del corral 3» o «los novillos que ya pueden venderse». Cada
 * listado declara qué filtros acepta; los filtros se arman acá como SQL con
 * parámetros, y la misma consulta sirve para la vista previa, la planilla CSV,
 * el Excel y el PDF. Así lo que se ve antes de descargar es lo que se descarga.
 *
 * Lo exportado es siempre del predio activo, y cada listado exige el mismo
 * permiso que su pantalla.
 */

const CATEGORIAS_ANIMAL = [
    'TERNERO' => 'Ternero', 'TERNERA' => 'Ternera', 'NOVILLO' => 'Novillo', 'VAQUILLA' => 'Vaquilla',
    'VACA' => 'Vaca', 'TORO' => 'Toro', 'BUEY' => 'Buey',
];
const SEXOS_ANIMAL = ['HEMBRA' => 'Hembra', 'MACHO' => 'Macho', 'MACHO_CASTRADO' => 'Macho castrado'];
const ETAPAS_ANIMAL = ['CRIA' => 'Cría', 'RECRIA' => 'Recría', 'ENGORDA' => 'Engorda', 'REPRODUCCION' => 'Reproducción'];
const ESTADOS_ANIMAL = [
    'ACTIVO' => 'Activos', 'TODOS' => 'Todos', 'VENDIDO' => 'Vendidos', 'FAENADO' => 'Faenados',
    'TRASLADADO' => 'Trasladados a terceros', 'MUERTO' => 'Muertos',
];

/**
 * @return array<string, array{nombre: string, descripcion: string, permiso: string, titulo: list<string>,
 *         consulta: string, orden: string, alias: ?string, fecha: ?string, numeros: list<int>}>
 */
function listados_exportables(): array
{
    return [
        'animales' => [
            'nombre' => 'Inventario de animales',
            'descripcion' => 'Identificación, categoría, pesos y ubicación; con filtros por categoría, edad, sexo, corral, peso y resguardo',
            'permiso' => 'ver_hacienda',
            'titulo'  => ['DIIO', 'Número interno', 'EID', 'Sexo', 'Categoría', 'Raza', 'Etapa', 'Corral',
                          'Nacimiento', 'Destete', 'Secado', 'Ingreso', 'Peso ingreso', 'Peso actual', 'Último pesaje', 'Estado'],
            'consulta' => "SELECT a.caravana_visual, a.numero_interno, a.eid, a.sexo, a.categoria, a.raza, a.etapa,
                                  c.nombre AS corral, a.fecha_nacimiento, a.fecha_destete, a.fecha_secado, a.fecha_ingreso,
                                  a.peso_ingreso_kg, a.peso_actual_kg, a.fecha_ultima_pesada, a.estado
                             FROM animales a
                             LEFT JOIN corrales c ON c.id = a.corral_actual_id
                            WHERE a.organizacion_id = ?",
            'orden'   => ' ORDER BY COALESCE(a.caravana_visual, a.numero_interno)',
            'alias'   => 'a',
            'fecha'   => null,
            'numeros' => [12, 13],
        ],
        'partos' => [
            'nombre' => 'Partos',
            'descripcion' => 'Fecha, vaca, resultado, dificultad y ternero',
            'permiso' => 'ver_reproduccion',
            'titulo'  => ['Fecha', 'Vaca', 'Resultado', 'Dificultad', 'Ternero débil', 'Ternero', 'Peso al nacer', 'Observaciones'],
            'consulta' => "SELECT p.fecha, COALESCE(m.caravana_visual, m.numero_interno) AS vaca, p.resultado, p.dificultad,
                                  CASE WHEN p.ternero_debil = 1 THEN 'Sí' ELSE 'No' END AS debil,
                                  COALESCE(t.caravana_visual, t.numero_interno) AS ternero,
                                  p.peso_nacimiento_kg, p.observaciones
                             FROM partos p
                             JOIN animales m ON m.id = p.vaca_id
                             LEFT JOIN animales t ON t.id = p.ternero_id
                            WHERE p.organizacion_id = ?",
            'orden'   => ' ORDER BY p.fecha DESC',
            'alias'   => 'm',
            'fecha'   => 'p.fecha',
            'numeros' => [6],
        ],
        'entregas' => [
            'nombre' => 'Entregas de alimento',
            'descripcion' => 'Qué se entregó, a qué corral o animal y cuánta materia seca',
            'permiso' => 'ver_alimentacion',
            'titulo'  => ['Fecha', 'Corral', 'Animal', 'Ración', 'Kilos entregados', 'Kilos materia seca', 'Entrega del día'],
            'consulta' => 'SELECT e.fecha, c.nombre AS corral, COALESCE(a.caravana_visual, a.numero_interno) AS animal,
                                  r.nombre AS racion, e.kg_entregados, e.kg_materia_seca, e.numero_entrega
                             FROM entregas_alimento e
                             JOIN raciones r ON r.id = e.racion_id
                             LEFT JOIN corrales c ON c.id = e.corral_id
                             LEFT JOIN animales a ON a.id = e.animal_id
                            WHERE e.organizacion_id = ? AND e.anulada = 0',
            'orden'   => ' ORDER BY e.fecha DESC',
            'alias'   => 'c',
            'fecha'   => 'e.fecha',
            'numeros' => [4, 5, 6],
        ],
        'salidas' => [
            'nombre' => 'Salidas del predio (FMA)',
            'descripcion' => 'Ventas, faenas y traslados: un DIIO por fila, con origen y destino',
            'permiso' => 'registrar_venta',
            'titulo'  => ['Fecha', 'Tipo', 'DIIO', 'Sexo', 'Categoría', 'RUP origen', 'RUP destino', 'Destino', 'Comprador', 'Patente'],
            'consulta' => 'SELECT s.fecha, s.tipo, d.diio, a.sexo, a.categoria, e.rup AS origen, s.destino_rup,
                                  s.destino_nombre, s.comprador, s.patente
                             FROM salidas s
                             JOIN detalles_salida d ON d.salida_id = s.id
                             JOIN animales a ON a.id = d.animal_id
                             JOIN establecimientos e ON e.id = s.establecimiento_id
                            WHERE s.organizacion_id = ?',
            'orden'   => ' ORDER BY s.fecha DESC, d.diio',
            'alias'   => 's',
            'fecha'   => 's.fecha',
            'numeros' => [],
        ],
        'notas' => [
            'nombre' => 'Notas de las fichas',
            'descripcion' => 'Las observaciones escritas en cada animal',
            'permiso' => 'ver_hacienda',
            'titulo'  => ['Fecha', 'Animal', 'Nota', 'Quién la escribió'],
            'consulta' => 'SELECT n.fecha, COALESCE(a.caravana_visual, a.numero_interno) AS animal, n.texto,
                                  COALESCE(u.nombre, \'\') AS autor
                             FROM notas_animal n
                             JOIN animales a ON a.id = n.animal_id
                             LEFT JOIN usuarios u ON u.id = n.usuario_id
                            WHERE n.organizacion_id = ? AND n.anulada = 0',
            'orden'   => ' ORDER BY n.fecha DESC',
            'alias'   => 'a',
            'fecha'   => 'n.fecha',
            'numeros' => [],
        ],
        'bitacora' => [
            'nombre' => 'Bitácora',
            'descripcion' => 'Correcciones, anulaciones, accesos y exportaciones',
            'permiso' => 'ver_bitacora',
            'titulo'  => ['Cuándo', 'Qué pasó', 'Quién', 'Entidad', 'Detalle', 'Origen'],
            'consulta' => "SELECT b.fecha_hora, b.accion, COALESCE(u.nombre, '') AS quien, b.entidad, b.detalle, b.origen
                             FROM bitacora b
                             LEFT JOIN usuarios u ON u.id = b.usuario_id
                            WHERE (b.organizacion_id = ? OR b.organizacion_id IS NULL)",
            'orden'   => ' ORDER BY b.fecha_hora DESC',
            'alias'   => null,
            'fecha'   => 'b.fecha_hora',
            'numeros' => [],
        ],
    ];
}

/**
 * Atajos para los pedidos más comunes del inventario: un clic arma el filtro.
 *
 * @return array<string, array<string, mixed>> nombre => parámetros
 */
function atajos_inventario(): array
{
    return [
        'Terneros nacidos en los últimos 30 días' => ['categoria' => ['TERNERO', 'TERNERA'], 'nacido_desde' => hace_dias(30)],
        'Vacas' => ['categoria' => ['VACA']],
        'Vaquillas' => ['categoria' => ['VAQUILLA']],
        'Novillos de 400 kg o más' => ['categoria' => ['NOVILLO'], 'peso_min' => '400'],
        'Aptos para venta (sin resguardo)' => ['resguardo' => 'SIN'],
        'En resguardo sanitario' => ['resguardo' => 'CON'],
        'Vendidos, faenados y trasladados' => ['estado' => 'TODOS', 'egresados' => '1'],
    ];
}

/** Toma un texto de $_GET o de un arreglo de filtros, sin romperse con arreglos. */
function filtro_texto(array $f, string $campo): string
{
    return is_scalar($f[$campo] ?? null) ? trim((string) $f[$campo]) : '';
}

/**
 * Condiciones SQL de los filtros pedidos, con sus parámetros y la descripción
 * legible de lo aplicado (para el encabezado del PDF y la bitácora).
 *
 * Todo valor que llega se valida contra su lista o su tipo: lo que no se
 * reconoce se ignora, no se mete en la consulta.
 *
 * @return array{sql: string, parametros: list<mixed>, aplicados: list<string>}
 */
function condiciones_exportacion(int $org, string $tipo, array $f, ?int $predio): array
{
    $listado = listados_exportables()[$tipo];
    $sql = '';
    $parametros = [];
    $aplicados = [];
    $fecha = static fn (string $campo): ?string => fecha_valida(filtro_texto($f, $campo)) ? filtro_texto($f, $campo) : null;

    if ($tipo === 'animales') {
        $categorias = array_values(array_intersect(
            array_keys(CATEGORIAS_ANIMAL),
            is_array($f['categoria'] ?? null) ? array_map('strval', $f['categoria']) : (filtro_texto($f, 'categoria') !== '' ? [filtro_texto($f, 'categoria')] : []),
        ));
        if ($categorias !== []) {
            $sql .= ' AND a.categoria IN (' . implode(',', array_fill(0, count($categorias), '?')) . ')';
            array_push($parametros, ...$categorias);
            $aplicados[] = implode(', ', array_map(static fn (string $c): string => CATEGORIAS_ANIMAL[$c], $categorias));
        }
        if (isset(SEXOS_ANIMAL[filtro_texto($f, 'sexo')])) {
            $sql .= ' AND a.sexo = ?';
            $parametros[] = filtro_texto($f, 'sexo');
            $aplicados[] = SEXOS_ANIMAL[filtro_texto($f, 'sexo')];
        }
        if (isset(ETAPAS_ANIMAL[filtro_texto($f, 'etapa')])) {
            $sql .= ' AND a.etapa = ?';
            $parametros[] = filtro_texto($f, 'etapa');
            $aplicados[] = 'Etapa ' . ETAPAS_ANIMAL[filtro_texto($f, 'etapa')];
        }
        if (ctype_digit(filtro_texto($f, 'corral_id'))) {
            $corral = fila('SELECT id, nombre FROM corrales WHERE organizacion_id = ? AND id = ?', [$org, (int) filtro_texto($f, 'corral_id')]);
            if ($corral !== null) {
                $sql .= ' AND a.corral_actual_id = ?';
                $parametros[] = (int) $corral['id'];
                $aplicados[] = 'Corral ' . $corral['nombre'];
            }
        }
        if (filtro_texto($f, 'raza') !== '') {
            $sql .= ' AND a.raza = ?';
            $parametros[] = mb_substr(filtro_texto($f, 'raza'), 0, 60);
            $aplicados[] = 'Raza ' . filtro_texto($f, 'raza');
        }
        if (($desde = $fecha('nacido_desde')) !== null) {
            $sql .= ' AND a.fecha_nacimiento >= ?';
            $parametros[] = $desde;
            $aplicados[] = 'Nacidos desde ' . fecha_corta($desde);
        }
        if (($hasta = $fecha('nacido_hasta')) !== null) {
            $sql .= ' AND a.fecha_nacimiento <= ?';
            $parametros[] = $hasta;
            $aplicados[] = 'Nacidos hasta ' . fecha_corta($hasta);
        }
        foreach (['peso_min' => ['>=', 'Desde'], 'peso_max' => ['<=', 'Hasta']] as $campo => [$operador, $texto]) {
            if (is_numeric(filtro_texto($f, $campo))) {
                $sql .= ' AND a.peso_actual_kg ' . $operador . ' ?';
                $parametros[] = (float) filtro_texto($f, $campo);
                $aplicados[] = $texto . ' ' . numero((float) filtro_texto($f, $campo)) . ' kg';
            }
        }

        // Estado: por defecto, los activos. «Egresados» = todo lo que ya salió.
        $estado = isset(ESTADOS_ANIMAL[filtro_texto($f, 'estado')]) ? filtro_texto($f, 'estado') : 'ACTIVO';
        if (filtro_texto($f, 'egresados') === '1') {
            $sql .= " AND a.estado IN ('VENDIDO', 'FAENADO', 'TRASLADADO')";
            $aplicados[] = 'Vendidos, faenados y trasladados';
        } elseif ($estado !== 'TODOS') {
            $sql .= ' AND a.estado = ?';
            $parametros[] = $estado;
            if ($estado !== 'ACTIVO') {
                $aplicados[] = ESTADOS_ANIMAL[$estado];
            }
        } else {
            $aplicados[] = 'Todos los estados';
        }

        // Resguardo: se calcula en PHP (depende de la fecha), y se filtra por id.
        $resguardo = filtro_texto($f, 'resguardo');
        if (in_array($resguardo, ['CON', 'SIN'], true)) {
            $ids = array_keys(carencias_activas($org, $predio));
            if ($resguardo === 'CON') {
                $sql .= $ids === [] ? ' AND 1 = 0' : ' AND a.id IN (' . implode(',', array_map('intval', $ids)) . ')';
                $aplicados[] = 'En resguardo sanitario';
            } else {
                $sql .= $ids === [] ? '' : ' AND a.id NOT IN (' . implode(',', array_map('intval', $ids)) . ')';
                $aplicados[] = 'Sin resguardo (aptos para salir)';
            }
        }
    }

    if ($listado['fecha'] !== null) {
        if (($desde = $fecha('desde')) !== null) {
            $sql .= ' AND ' . $listado['fecha'] . ' >= ?';
            $parametros[] = $desde;
            $aplicados[] = 'Desde ' . fecha_corta($desde);
        }
        if (($hasta = $fecha('hasta')) !== null) {
            // fecha_hora trae la hora: se compara contra el final del día.
            $sql .= ' AND ' . $listado['fecha'] . ' <= ?';
            $parametros[] = $hasta . ' 23:59:59';
            $aplicados[] = 'Hasta ' . fecha_corta($hasta);
        }
    }

    return ['sql' => $sql, 'parametros' => $parametros, 'aplicados' => $aplicados];
}

/**
 * Filas del listado con los filtros aplicados, crudas (sin formatear).
 *
 * @return array{filas: list<array<int, mixed>>, aplicados: list<string>}
 */
function filas_exportacion(int $org, string $tipo, array $f, ?int $predio): array
{
    $listado = listados_exportables()[$tipo];
    $condiciones = condiciones_exportacion($org, $tipo, $f, $predio);
    $del_predio = ($listado['alias'] !== null && $predio !== null) ? ' AND ' . $listado['alias'] . '.establecimiento_id = ' . (int) $predio : '';
    $filas = filas(
        $listado['consulta'] . $del_predio . $condiciones['sql'] . $listado['orden'],
        array_merge([$org], $condiciones['parametros']),
    );
    return ['filas' => array_map('array_values', $filas), 'aplicados' => $condiciones['aplicados']];
}

/**
 * Filas listas para Excel: fechas y estados legibles como en el resto de las
 * exportaciones, pero las columnas numéricas quedan como número para poder sumar.
 */
function filas_para_excel(string $tipo, array $filas): array
{
    $numeros = listados_exportables()[$tipo]['numeros'];
    return array_map(static function (array $fila) use ($numeros): array {
        foreach ($fila as $i => $valor) {
            $fila[$i] = in_array($i, $numeros, true) && is_numeric($valor) ? (float) $valor : valor_exportado($valor);
        }
        return $fila;
    }, $filas);
}
