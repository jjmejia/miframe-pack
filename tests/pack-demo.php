<?php
/**
 * Script para probar implementación de clase Pack.php
 *
 * @author John Mejía
 * @since Mayo 2022
 */

include __DIR__ . '/../src/pack.php';
include __DIR__ . '/../src/functions.php';

$pack = new \miFrame\Utils\Pack();

// Ejemplo de generación de un valor numerico en bytes
// $n = 10228995;
// $bytes = $pack->getBytes($n);
// $v = $pack->getValue($bytes);
// echo "$n = $v / " . urlencode($bytes). "<hr>";

$cmd = '';
if (isset($_REQUEST['cmd'])) { $cmd = strtolower(trim($_REQUEST['cmd'])); }

if ($cmd == 'export') {
	// Comprime archivo y lo envia luego al navegador
	$pack->exportFile('superman2.miframe-pack');
	exit;
}

apertura();

$t = microtime(true);

$s = 'Un elefante se balanceaba sobre la tela de una araña, como veia que resistia fueron a llamar otro elefante.';

echo '<p><a href="?cmd=create">Crear archivo "prueba.miframe-pack"</a></p>';

if ($cmd == 'create') {
	// Crea paquete de datos
	$pack->put('prueba.miframe-pack', $s, true);
	$pack->put('prueba.miframe-pack', $s.'/'.date('Y-m-d H:i:s'));
	$pack->put('prueba.miframe-pack', 'Hola mundo');

	echo "<ul><li>Archivo creado (" . ffilesize('prueba.miframe-pack') . " bytes)</li></ul>";
	echo '<pre style="padding-left:40px">' . wordwrap(str_replace(array('%2F', '%'), array('/', '.'), urlencode(file_get_contents('prueba.miframe-pack'))), 120, "\n", true) . "</pre>";
}

echo '<p><a href="?cmd=text">Crear archivo "prueba.miframe-pack" en modo texto</a></p>';

if ($cmd == 'text') {
	// Crea paquete de datos
	$pack->text('prueba.miframe-pack', $s, true, true);
	$pack->text('prueba.miframe-pack', $s.'/'.date('YmdHis'));
	$pack->text('prueba.miframe-pack', 'Hola mundo');

	echo "<ul><li>Archivo creado (" . ffilesize('prueba.miframe-pack') . " bytes)</li></ul>";
	echo '<pre style="padding-left:40px">' . file_get_contents('prueba.miframe-pack') . "</pre>";
}

if (file_exists('prueba.miframe-pack')) {
	echo '<p><a href="?cmd=recover">Recuperar datos de archivo "prueba.miframe-pack"</a></p>';
}

if ($cmd == 'recover') {
	// Recupera registros
	$data = $pack->get('prueba.miframe-pack', 1);
	echo '<p><b>Modo:</b> ' . $pack->getMode() . '</p>';
	echo '<ul>';
	if ($data === false) { $data = "(" . $pack->getLastError() . ")"; }
	echo "<li><b>Bloque #1:</b> $data</li>";

	$data = $pack->get('prueba.miframe-pack', 2);
	if ($data === false) { $data = "(" . $pack->getLastError() . ")"; }
	echo "<li><b>Bloque #2:</b> $data</li>";

	$data = $pack->get('prueba.miframe-pack', 3);
	if ($data === false) { $data = "(" . $pack->getLastError() . ")"; }
	echo "<li><b>Bloque #3:</b> $data</li>";

	$data = $pack->get('prueba.miframe-pack', 10); // No existe
	if ($data === false) { $data = "(" . $pack->getLastError() . ")"; }
	echo "<li><b>Bloque #10:</b> $data</li>";
	echo '</ul>';
}

echo '<p><a href="?cmd=readimg">Crear archivo "superman.miframe-pack" leyendo datos de "superman.jpg"</a></p>';

if ($cmd == 'readimg') {
	// Comprime archivo directamente (se revienta con archivos grandes)
	$s = file_get_contents('superman.jpg');
	$pack->put('superman.miframe-pack', $s, true);
	echo "<ul><li>Archivo superman.miframe-pack creado (pack = " . ffilesize('superman.miframe-pack') . " / original = " .
		ffilesize('superman.jpg') . " bytes)</li></ul>";
}

echo '<p><a href="?cmd=compact">Compactar directamente archivo "superman.jpg" a "superman2.miframe-pack"</a></p>';

if ($cmd == 'compact') {
	// Comprime archivo completo
	$bloques = $pack->compressFile('superman.jpg', 'superman2.miframe-pack', false, true);
	echo "<ul><li>Archivo creado con $bloques bloques (pack = " . ffilesize('superman2.miframe-pack') . " / original = " .
		ffilesize('superman.jpg') . " bytes)</li></ul>";
}

if (file_exists('superman2.miframe-pack')) {
	echo '<p><a href="?cmd=expand">Descompactar archivo "superman2.miframe-pack"</a></p>';
}

if ($cmd == 'expand') {
	// Comprime archivo completo
	$pack->uncompressFile('superman2.miframe-pack', 'superman2.jpg', true);
	echo "<ul><li>Archivo recuperado (" . ffilesize('superman2.jpg') . ")</li></ul>";
}

if (file_exists('superman2.miframe-pack')) {
	echo '<p><a href="?cmd=export" target="_blank">Exportar archivo "superman2.miframe-pack"</a></p>';
}

if ($cmd != '') {
	echo '<p><a href="' . basename(__FILE__) . '">Empezar de nuevo...</a></p>';
	echo "<p>DURACION: " . (microtime(true) - $t);
}

cierre();

function ffilesize(string $filename) {

	return number_format(filesize($filename), 0, '', '.');
}

function apertura() {

?>
<!DOCTYPE html>
<html>
	<head>
		<title>Test Pack</title>
	</head>
<body>

<style>
body {
	font-family: "Segoe UI",Helvetica,Arial,sans-serif;
	font-size: 14px;
	line-height: 1.5;
	word-wrap: break-word;
	/* padding:0 20px; */
	}
h1 {
    padding-bottom: .3em;
    font-size: 2em;
    border-bottom: 1px solid hsla(210,18%,87%,1);
	}
h2 {
	margin-top: 24px;
}
h1, h2 {
	margin-bottom: 16px;
	font-weight: 600;
	line-height: 1.25;
}
code {
	background: rgba(175,184,193,0.2);
	font-size: 14px;
	padding:0 5px;
	font-family: Consolas;
}
pre.code {
	background: rgba(175,184,193,0.2);
	border: 1px solid #d0d7de;
	padding:16px;
}
</style>

<h1>Test Pack</h1>

<?php
}

function cierre() {

	// echo '<p style="border-top:1px solid #ccc; padding-top:10px;margin-top:20px;">Ejecución terminada con éxito</p>';
	echo '</body></html>';
}
