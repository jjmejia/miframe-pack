<?php
/**
 * Clase para creación de paquetes de datos en archivos.
 *
 * Esta clase cumple con los siguientes objetivos:
 * - Seguridad. Al tener un formato diferente para empaquetar datos, evita que los archivos puedan ser abiertos con
 *   cualquier aplicación disponible comercialmente, como pasaría con un ZIP u otro similar.
 * - Funcionalidad. Permite guardar bloques de datos generados y recuperados para uso de aplicaciones PHP, por ej. para
 *   guardar algo en caché.
 * - Confidencialidad. Al empaquetar archivos en el servidor (por ej. archivos recibidos del usuario) puede protegerlos
 *   de "miradas" indiscretas o que sean directamente interpretados por el navegador.
 * - Compartir. Aunque el formato por defecto almacena bloques comprimidos usando gzcompress(), que ya incluye el chequeo
 *   de datos al recuperarlos, también pueden generarse "packs" en formato texto para el caso que los archivos sean
 *   compartidos con aplicaciones que no puedan implementar el mismo esquema usado en el modelo binario. En este caso, se
 *   empaquetan usando Base64.
 *
 * Para información sobre las diferentes opciones de compresión puede consultar:
 * https://stackoverflow.com/questions/621976/which-compression-method-to-use-in-php
 *
 * Incluye utilidades para empaquetar también archivos (como alternativa a ZIP) y recuperarlos sea
 * directo a otro archivo o al navegador.
 *
 * @uses miframe/common/functions
 * @author John Mejia
 * @since Mayo 2022
 */

namespace miFrame\Utils;

/**
 * Clase para creación de paquetes de datos en archivos.
 * Propiedades publicas:
 * - $debug: boolean. TRUE para presentar mensajes en pantalla.
 * - $chunkSize: integer. Tamaño máximo de los bloques de datos a guardar.
 */
class Pack {

	private $version = '';
	private $debug = false;
	private $last_error = '';
	private $f = false;
	private $modo_bin = true;

	public $chunkSize = 0;

	public function __construct() {
		// Inicializa parámetros
		$this->version = 'MIFRAMEPACK/1.0/';
		$this->chunkSize = 10485760; // Tamaño de bloque de datos 10MB
	}

	/**
	 * Retorna un valor numerico en un arreglo de bytes.
	 * El valor máximo soportado es de 72.057.594.037.927.935, que corresponde a una cifra de 7 bytes.
	 * Basado en: https://stackoverflow.com/questions/3607757/write-large-4-byte-integer-as-an-unsigned-binary
	 *
	 * @param int $value Valor numerico.
	 * @return array Arreglo con los bytes equivalentes.
	 */
	private function getBytes(int $value) {

		$n = array();
		while ($value > 0) {
			$n[] = chr($value & 0xff);
			$value = ($value >> 8);
		}

		return $n;
	}

	/**
	 * Retorna valor númerico asociado a una cadena texto.
	 * La cadena texto debe ser una representación del valor numérico en bytes.
	 *
	 * @param string $bytes Cadena texto.
	 * @return int Valor equivalente.
	 */
	private function getValue(string $bytes) {

		if ($bytes === '') { return 0; }

		$value = ord($bytes[0]);
		$totalbytes = strlen($bytes);
		for ($i = 1; $i < $totalbytes; $i ++) {
			$num = ord($bytes[$i]);
			$value += ($num << ($i * 8));
		}

		return $value;
	}

	/**
	 * Retorna el modo de empaquetado usado.
	 *
	 * @return string Binary/Text
	 */
	public function getMode() {

		$modo = 'binary';
		if (!$this->modo_bin) {
			$modo = 'text';
		}

		return $modo;
	}

	/**
	 * Abre un archivo pack para escritura.
	 *
	 * @param string $filename Nombre del archivo destino.
	 * @param bool $rewrite TRUE para reescribir el archivo si existe. FALSE adiciona valores.
	 * @return bool TRUE si pudo abrir el archivo o FALSE si ocurrió algún error.
	 */
	public function fopenWrite(string $filename, bool $rewrite = false) {

		return $this->fopen($filename, $rewrite, false);
	}

	/**
	 * Abre un archivo pack para lectura.
	 *
	 * @param string $filename Nombre del archivo destino.
	 * @return bool TRUE si pudo abrir el archivo o FALSE si ocurrió algún error.
	 */
	public function fopenRead(string $filename) {

		return $this->fopen($filename, false, true);
	}

	/**
	 * Abre un archivo pack sea para lectura o escritura.
	 * Para escritura, adiciona cabecera al archivo pack si este no existe o es remplazado.
	 * Para lectura, valida que la cabecera coincida con la esperada según $this->version.
	 *
	 * @param string $filename Nombre del archivo destino.
	 * @param bool $rewrite TRUE para reescribir el archivo si existe. FALSE adiciona valores.
	 * @param bool $readonly TRUE abre el archivo para sólo lectura, FALSE para escritura.
	 * @return bool TRUE si pudo abrir el archivo o FALSE si ocurrió algún error.
	 */
	private function fopen(string $filename, bool $rewrite = false, bool $readonly = false) {

		$resultado = false;
		$this->last_error = '';

		$permisos = 'r';
		if (!$readonly) {
			$permisos = 'w';
			// Si existe y se indica adicionar...
			if (file_exists($filename) && !$rewrite) { $permisos = 'r+'; }
		}

		// Abre archivos en modo binario
		$this->f = fopen($filename, $permisos . 'b');
		// Añade cabecera si el archivo es nuevo
		if ($this->f !== false) {
			$resultado = true;

			if ($permisos == 'w') {
				// Escritura completa
				$modo = 'B'; // Binario
				if (!$this->modo_bin) {
					$modo = 'T'; // Texto
				}
				$cabezote = $this->version . $modo;
				$resultado = (fwrite($this->f, $cabezote) == strlen($cabezote));
				if (!$resultado) {
					$this->last_error = 'No pudo escribir encabezado al archivo';
				}
			}
			else {
				// $permisos == 'r' || $permisos == 'r+'
				$len = strlen($this->version) + 1; // cabecera + modo (B/T)
				// Recupera cabezote
				$cab = fread($this->f, $len);
				if ($cab !== false && strlen($cab) == $len) {
					$modo = substr($cab, -1, 1);
					$resultado = (substr($cab, 0, -1) == $this->version
						&& ($modo == 'B' || $modo == 'T')
						&& ($readonly || $this->modo_bin == ($modo == 'B'))
						);
					if (!$resultado) {
						$this->last_error = 'La cabecera del archivo no coincide con el valor/modo esperado';
					}
					elseif ($permisos == 'r+') {
						// Se posiciona al final para adicionar datos
						fseek($this->f, 0, SEEK_END);
					}
					// Recupera modo ()
					$this->modo_bin = ($modo == 'B');
					// PENDIENTE: Validar acciones para versiones anteriores
				}
			}
			if (!$resultado) {
				// Alguna operación de validacion falló
				fclose($this->f);
				$this->f = false;
			}
		}

		return $resultado;
	}

	/**
	 * Cierra archivo pack.
	 */
	public function fclose() {

		if ($this->f !== false) {
			fclose($this->f);
		}
	}

	/**
	 * Retorna total de bloques a escribir a disco.
	 *
	 * @param int $size Tamaño de los datos a escribir
	 * @return int Total de bloques
	 */
	private function getChunks(int $size) {

		$bloques = 1;
		if ($this->chunkSize > 0 && $size > $this->chunkSize) {
			$bloques = ceil($size / $this->chunkSize);
		}

		return $bloques;
	}

	/**
	 * Escribe bloque de datos en un archivo pack abierto.
	 *
	 * @param string $data Datos a guardar.
	 * @return bool TRUE si la escritura de datos se realiza sin errores, FALSE en otro caso.
	 */
	public function fputs(string $data) {

		$resultado = false;

		if ($this->f !== false) {

			$compact = '';
			$pre = '';
			$lendata = strlen($data);
			$bloques = $this->getChunks($lendata);

			if ($bloques > 1) {
				$this->last_error = 'El tamaño del dato a guardar es mayor al soportado (' . $lendata . ' > ' . $this->chunkSize . ')';
				return false;
			}

			if ($this->modo_bin) {
				// Modo binario
				$nivel = 9;
				// Si el bloque pesa mas de 1M, baja el nivel de compresión a 7 por velocidad
				if (strlen($data) >= 1045504) { $nivel = 7; }
				$compact = gzcompress($data, $nivel);
			}
			else {
				// Modo texto
				$compact = wordwrap(str_replace('=', '', base64_encode($data)), 1024, PHP_EOL, true);
			}

			// Valida si mantiene el original o el comprimido? No porque la idea es ocultar el contenido
			// y tener una validación de integridad del contenido, que provee gzompress().
			$len = strlen($compact);
			$bytes = $this->getBytes($len);
			$numbytes = count($bytes);
			// $numbytes = hasta 7 para no reventar PHP
			if ($numbytes <= 0 || $numbytes > 7) {
				$this->last_error = 'Error al obtener tamaño de bloque o tamaño no soportado (' . $len . ')';
				return false;
			}

			// Encabezado del bloque
			if ($this->modo_bin) {
				$pre = chr($numbytes) . implode('', $bytes);
			}
			else {
				$pre = PHP_EOL . '#' . dechex($len) .
						// En la practica, dechex() traduce hex de hasta 7 bytes sin problema
						':' . md5($compact) . PHP_EOL;
						// Adiciona md5 para chequeo del bloque de datos
			}

			$len += strlen($pre);

			$resultado = (fwrite($this->f, $pre . $compact) == $len);
		}

		return $resultado;
	}

	/**
	 * Adiciona datos a un archivo pack.
	 * Si el archivo no existe, será creado. Si ya existe se tiene la opción de remplazarlo o adicionar
	 * nuevos bloques de datos pack.
	 *
	 * @param string $filename Nombre del archivo destino.
	 * @param string $data Datos a guardar.
	 * @param bool $rewrite TRUE para reescribir el archivo si existe. FALSE adiciona bloques.
	 * @return bool TRUE si la escritura de datos se realiza sin errores, FALSE en otro caso.
	 */
	public function put(string $filename, string $data, bool $rewrite = false) {

		$resultado = false;
		$this->last_error = '';

		if ($this->fopenWrite($filename, $rewrite) !== false) {
			$resultado = $this->fputs($data);
			if (!$resultado) {
				$this->last_error = 'No pudo escribir bloque de datos al archivo pack';
			}
			fclose($this->f);
		}
		else {
			$this->last_error = 'No pudo crear archivo pack';
		}

		return $resultado;
	}

	/**
	 * Adiciona datos a un archivo pack en modo texto.
	 * Para todos los demás efectos, se comporta igual que put().
	 *
	 * @param string $filename Nombre del archivo destino.
	 * @param string $data Datos a guardar.
	 * @param bool $rewrite TRUE para reescribir el archivo si existe. FALSE adiciona bloques.
	 * @return bool TRUE si la escritura de datos se realiza sin errores, FALSE en otro caso.
	 */
	public function text(string $filename, string $data, bool $rewrite = false) {

		$this->modo_bin = false;
		$resultado = $this->put($filename, $data, $rewrite);
		$this->modo_bin = true; // Restablece modo binario

		return $resultado;
	}

	/**
	 * Recupera datos comprimidos de un archivo pack abierto.
	 *
	 * @param int $max_length Longitud del bloque de datos comprimido.
	 * @param int $offset Posición de inicio de lectura (por defecto en la posición actual dentro del archivo).
	 * @return string/false Retorna datos sin comprimir o FALSE si ocurre algún error.
	 */
	public function extract(int $max_length, string $md5 = '', int $offset = -1) {

		if ($this->f !== false) {
			if ($offset > 0) {
				// Reubica puntero
				fseek($this->f, $offset, SEEK_SET);
			}

			$data = fread($this->f, $max_length);

			if ($data !== false && strlen($data) == $max_length) {
				if ($this->modo_bin) {
					return gzuncompress($data);
				}
				elseif ($md5 == md5($data)) {
					return base64_decode($data);
				}
			}
		}

		return false;
	}

	/**
	 * Recupera bloques de datos de un archivo pack abierto.
	 *
	 * @param bool $recover TRUE para recuperar los datos guardados, FALSE solo para validar y avanzar al siguiente bloque.
	 * @return string/false Retorna datos sin comprimir o FALSE si ocurre algún error.
	 */
	public function fgets(bool $recover = true) {

		$resultado = false;
		$this->last_error = '';

		$continuar = true;
		$data = '';
		$len = 0;
		$md5 = '';

		if ($this->modo_bin) {
			// Recupera formato (longitud del tamaño de la data)
			$formato = fread($this->f, 1);
			if ($formato !== false && strlen($formato) == 1) { $formato = ord($formato); }
			if ($formato === false || $formato < 1 || ($formato > 7)) {
				// Si ha llegado al fin del archivo en este punto, simplemente retorna false pues puede
				// estar tratando de recuperar un bloque que no existe.
				if (!feof($this->f)) {
					$this->last_error = 'Error de lectura formato bloque / fin de archivo';
				}
				$continuar = false;
			}
			else {
				// Recupera bloque de datos
				$lendata = fread($this->f, $formato);
				if ($lendata === false) {
					$this->last_error = 'Error de lectura al recuperar tamaño de bloque';
					$continuar = false;
				}
				else {
					$len = $this->getValue($lendata);
				}
			}
		}
		else {
			// Modo texto
			fgets($this->f); // Fin de linea de la cabecera del archivo
			$linea = fgets($this->f); // Primer linea de datos
			if ($linea !== false && strlen($linea) >= 35 && $linea[0] == '#') {
				// # (tamaño hex) : (md5) > 35
				$arreglo = explode(':', rtrim(substr($linea, 1)) . ':');
				$len = hexdec($arreglo[0]);
				$md5 = $arreglo[1];
			}
			else {
				$this->last_error = 'Error de lectura formato bloque / fin de archivo';
				$continuar = false;
			}
		}

		if ($continuar && $len > 0) {
			if ($recover) {
				$data = $this->extract($len, $md5);
				if ($data !== false) {
					return $data;
				}
			}
			else {
				// Mueve el apuntador simplemente
				$resultado = (fseek($this->f, $len, SEEK_CUR) >= 0);
			}
		}

		return $resultado;
	}

	/*private function debug_file(string $filename) {
		if (!$this->debug) { $filename = basename($filename); }
		return $filename;
	}*/

	/**
	 * Recupera bloque de datos de un archivo.
	 *
	 * @param string $filename Nombre del archivo destino.
	 * @param int $index Número de bloque a recuperar (el primero es marcado con "1").
	 * @return string/false Retorna datos sin comprimir o FALSE si ocurre algún error.
	 */
	public function get(string $filename, int $index = 0) {

		$resultado = false;
		$this->last_error = '';

		if ($this->fopenRead($filename) !== false) {

			if ($index <= 0) { $index = 1; }
			for ($i = 1; $i <= $index; $i++) {
				$resultado = $this->fgets(($i == $index)); // FALSE no descomprime datos
				if ($resultado === false) {
					if (!feof($this->f)) {
						$this->last_error = 'No pudo recuperar el bloque de datos #' . $i . ': ' . $this->last_error;
					}
					elseif ($i != $index) {
						$this->last_error = 'El bloque de datos #' . $index . ' no existe en el archivo';
					}
					break;
				}
			}
			fclose($this->f);
		}
		else {
			$this->last_error = 'No pudo abrir archivo pack para extraer bloque de datos';
		}

		return $resultado;
	}

	/**
	 * Asigna nombre de archivo basado en un path y nombre de archivo de referencia.
	 * Usado para casos en los que requiere asignar un nombre de archivo destino para extracción.
	 *
	 * @param string $src Nombre de archivo de referencia (ruta completa preferiblemente).
	 * @param string $path Path de destino. Si no se indica, lo recupera usando dirname($src).
	 * @param string $extension Extensión propuesta. Si no se indica, usa la del archivo en $src.
	 */
	private function autoname(string $src, string $path, string $extension = '') {

		$dest = $path;
		if ($dest != '') { $dest .= DIRECTORY_SEPARATOR; }
		if ($extension != '') {
			$dest .= pathinfo($src, PATHINFO_FILENAME) . '.' . $extension;
		}
		else {
			$dest .= basename($src);
		}

		return $dest;
	}

	/**
	 * Compacta archivo en bloques a un archivo pack.
	 * Requiere que $this->chunkSize tenga un valor mayor a cero.
	 *
	 * @param string $src Archivo origen.
	 * @param string $dest Archivo destino. Si no se indica, usa como base el nismo del origen con extensión "miframe-pack"
	 * @param bool $remove_src TRUE para remover archivo de origen si puede crear archivo pack con éxito.
	 * @param bool $replace_dest TRUE para remplazar archivo destino si ya existe.
	 * @return int/false Total de bloques copiados, FALSE si ocurrió algún error.
	 */
	public function compressFile(string $src, string $dest = '', bool $remove_src = false, bool $replace_dest = false) {

		$resultado = false;
		$bloques = 0;
		$this->last_error = '';

		if ($dest == '') {
			$dest = $this->autoname($src, dirname($src), '.miframe-pack');
		}

		if (file_exists($src)
			&& $this->chunkSize > 0
			&& $this->fopenWrite($dest, $replace_dest) !== false
			) {
			// Guarda información del archivo
			$size = filesize($src);
			$bloques = $this->getChunks($size);
			$arreglo = array(
				'file' => basename($src),
				'date' => filemtime($src),
				'size' => $size,
				'mime' => mime_content_type($src),
				'chks' => $bloques // Total de bloques
				);
			$resultado = $this->fputs(serialize($arreglo));
			// NOTA: En este caso no usa $this->putChunks() porque al leer NO debe cargar todo
			// el bloque a memoria o podría causar que se reviente el script.
			if ($resultado) {
				// Guarda contenido
				$fsrc = fopen($src, 'rb');
				if ($fsrc !== false) {
					$conteo = 0;
					while (!feof($fsrc) && $resultado) {
						$data = fread($fsrc, $this->chunkSize);
						$resultado = $this->fputs($data);
						$conteo ++;
					}
					fclose($fsrc);
					// Evalua resultado
					if (!$resultado) {
						$this->last_error = 'Ocurrió un error al copiar bloque #' . $conteo;
					}
					elseif ($conteo != $bloques) {
						$resultado = false;
						$this->last_error = 'El total de bloques copiado (' . $conteo . ') es diferente al esperado (' . $bloques . ')';
					}
				}
				else {
					$this->last_error = 'No pudo abrir archivo origen para compactar';
				}
			}
			else {
				$this->last_error = 'No pudo copiar información del archivo de origen al archivo pack';
			}
			$this->fclose($this->f);
		}

		if ($resultado) {
			if ($remove_src) {
				unlink($src);
			}
			return $bloques;
		}

		return $resultado;
	}

	/**
	 * Compacta archivo en bloques a un archivo pack de modo texto.
	 * Para todos los demás efectos, se comporta igual que compressFile().
	 *
	 * @param string $src Archivo origen.
	 * @param string $dest Archivo destino.
	 * @param bool $remove_src TRUE para remover archivo de origen si puede crear archivo pack con éxito.
	 * @param bool $replace_dest TRUE para remplazar archivo destino si ya existe.
	 * @return int/false Total de bloques copiados, FALSE si ocurrió algún error.
	 */
	public function compressFileText(string $src, string $dest, bool $remove_src = false, bool $replace_dest = false) {

		$this->modo_bin = false;
		$resultado = $this->compressFile($src, $dest, $remove_src, $replace_dest);
		$this->modo_bin = true; // Restablece modo binario

		return $resultado;
	}

	/**
	 * Recupera archivo compactado en bloques.
	 *
	 * @param string $src Archivo origen.
	 * @param string $dest Archivo destino.
	 * @param bool $replace_dest TRUE para remplazar archivo destino si ya existe.
	 * @return bool TRUE si pudo recuperar el archivo original, FALSE si ocurrió algún error.
	 */
	public function uncompressFile(string $src, string $dest = '', bool $replace_dest = false) {

		$resultado = false;
		$this->last_error = '';

		if ($dest == '') {
			$dest = $this->autoname($info['file'], dirname($src));
		}
		if (file_exists($dest) && !$replace_dest) {
			$this->last_error = 'Destino ya existe';
		}
		elseif ($this->fopenRead($src) !== false) {
			$info = $this->getFileinfo();
			if (is_array($info)) {
				// Valida destino
				$fdest = fopen($dest, 'wb');
				if ($fdest) {
					$size = $this->readfile($info['chks'], $fdest);
					fclose($fdest);
					// Valida tamaño
					$resultado = ($size == $info['size']);
					if (!$resultado) {
						unlink($dest);
						$this->last_error = 'Ocurrió error al validar tamaño del archivo destino (' . $size . ' / ' . $info['size'] . ')';
					}
					else {
						// Si el resultado es exitoso, restablece fecha de modificación original
						touch($dest, $info['date']);
					}
				}
				else {
					$this->last_error = 'No pudo crear archivo destino';
				}
			}
			fclose($this->f);
		}
		else {
			$this->last_error = 'No pudo abrir archivo pack con el archivo a recuperar';
		}

		return $resultado;
	}

	/**
	 * Recupera bloque de información de un archivo compactado en bloques.
	 *
	 * @return array Arreglo de datos del archivo compactado, FALSE si ocurrió algún error.
	 */
	private function getFileinfo() {

		$resultado = false;
		if ($this->f !== false) {
			$info = $this->fgets(true);
			if ($info !== false) {
				$info = unserialize($info);
				if (is_array($info)
					&& isset($info['file']) && $info['file'] != ''
 					&& isset($info['size']) && $info['size'] >= 0
					&& isset($info['date']) && $info['date'] > 0
					&& isset($info['mime']) && $info['mime'] != ''
					&& isset($info['chks']) && $info['chks'] > 0
					) {
					return $info;
					}
				else {
					$this->last_error = 'No pudo recuperar información del archivo original';
				}
			}
		}

		return $resultado;
	}

	/**
	 * Recupera archivo compactado en bloques.
	 * Los valores recuperados son enviados a un archivo destino o directamente a pantalla.
	 *
	 * @param int $chunks Número de bloques a recuperar.
	 * @param mixed $fdest Si es FALSE envía a pantalla directamente, si contiene un puntero de archivo, escribe datos a disco.
	 * @return int Total de datos recuperados.
	 */
	private function readfile(int $chunks, mixed $fdest = false) {

		$bloque = 1;
		$size = 0;
		$i = 0;

		if ($this->f !== false && $chunks > 0) {
			while (!feof($this->f) && $bloque <= $chunks) {
				$data = $this->fgets();
				if ($data !== false) {
					$parcial = 0;
					if ($fdest !== false) {
						$parcial = fwrite($fdest, $data);
					}
					else {
						echo $data;
						$parcial = strlen($data);
					}
					if ($parcial !== false) {
						$size += $parcial;
						$i++;
						// echo "Bloque $i / $size<hr>";
						}
					else {
						// Error al escribir al destino
						$this->last_error = 'Ocurrió un error al escribir en archivo destino';
						break;
					}
				}
				else {
					// Error o terminó
					$this->last_error = 'Ocurrió un error leyendo bloque #' . $bloque;
					break;
				}
				$bloque ++;
			}
		}

		return $size;
	}

	/**
	 * Recupera información de un archivo compactado en bloques.
	 * Automáticamente envía cabeceras http para que sea debidamente interpretado por el navegador.
	 *
	 * @param string $src
	 * @param bool $ignore_headers
	 * @return bool TRUE en caso que recupere y exporte todo el contenido correctamente, FALSE en otro caso.
	 */
	public function exportFile(string $src, bool $ignore_headers = false) {

		$resultado = false;
		$this->last_error = '';

		if ($this->fopenRead($src) !== false) {

			$info = $this->getFileinfo();
			if (is_array($info)) {

				if (!$ignore_headers) {
					header("Content-type: {$info['mime']}");
					if (strpos($info['mime'], 'image') !== false) {
						header("Content-Disposition: inline");
					}
					else {
						$file = basename($info['file']);
						header("Content-Disposition: attachment; filename={$file}");
					}
					header("Content-Length: {$info['size']}");
					header("Pragma: no-cache");
					header("Expires: 0");
				}

				$size = $this->readfile($info['chks']);

				// Valida tamaño
				$resultado = ($size == $info['size']);
				if (!$resultado) {
					$this->last_error = 'Ocurrió error al validar tamaño del archivo destino (' . $size . ' / ' . $info['size'] . ')';
				}
			}
			else {
				$this->last_error = 'No pudo recuperar información del archivo a exportar';
			}

			fclose($this->f);
		}
		else {
			$this->last_error = 'No pudo abrir archivo pack con el archivo a exportar';
		}

		return $resultado;
	}

	/**
	 * Retorna último mensaje de error.
	 *
	 * @return string Mensaje
	 */
	public function getLastError() {
		return $this->last_error;
	}
}