<?php
/**
 * Copies the gunsnprices PHP parser into shared/vendor/gunsnprices/ under an extension-specific namespace.
 *
 * Usage: php tools/vendor-parser.php [path/to/gunsnprices-php]
 *
 * OpenCart extensions are installed from a zip, without Composer, so the library
 * (https://github.com/gunsnprices/gunsnprices-php, Packagist gunsnprices/gunsnprices) is bundled. Its namespace
 * GunsNPrices is renamed to GunsNPricesCpr\Vendor\GunsNPrices so this copy cannot clash with another extension or a
 * Composer install of the same library. Every class reference in the library is a `namespace` line, a `use` line or a
 * global class (\RuntimeException, \LogicException); the result is checked with the PHP tokenizer and any name that
 * still starts with the original namespace stops the script. Nothing else is changed.
 *
 * Dev-only file, not shipped in the extension packages.
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}

$src = isset($argv[1]) ? rtrim($argv[1], '/') : dirname(__DIR__, 2) . '/gunsnprices-php';
$dest = dirname(__DIR__) . '/shared/vendor/gunsnprices';
$from = 'GunsNPrices';
$prefix = 'GunsNPricesCpr\\Vendor\\GunsNPrices';

if (!is_file("$src/src/Parser.php")) {
	fwrite(STDERR, "Parser source not found in $src\n");
	exit(1);
}

function gnp_dev_rmtree(string $dir): void {
	if (!is_dir($dir)) {
		return;
	}
	foreach (scandir($dir) as $f) {
		if ($f === '.' || $f === '..') {
			continue;
		}
		$p = "$dir/$f";
		is_dir($p) ? gnp_dev_rmtree($p) : unlink($p);
	}
	rmdir($dir);
}

gnp_dev_rmtree($dest);
mkdir("$dest/src/Internal", 0755, true);
mkdir("$dest/data", 0755, true);

$php_files = array_merge(['src/Parser.php'], array_map(function ($p) {
	return 'src/Internal/' . basename($p);
}, glob("$src/src/Internal/*.php")));
sort($php_files);

$version = '';
foreach ($php_files as $rel) {
	$code = file_get_contents("$src/$rel");
	$out = preg_replace(
		['/^namespace ' . $from . '(\\\\|;)/m', '/^use ' . $from . '\\\\/m'],
		['namespace ' . str_replace('\\', '\\\\', $prefix) . '$1', 'use ' . str_replace('\\', '\\\\', $prefix) . '\\\\'],
		$code
	);
	foreach (token_get_all($out) as $t) {
		if (is_array($t) && in_array($t[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
			$name = ltrim($t[1], '\\');
			if (strpos($name, $from . '\\') === 0) {
				fwrite(STDERR, "Unprefixed name $t[1] in $rel line $t[2]\n");
				exit(1);
			}
		}
	}
	if ($rel === 'src/Parser.php' && preg_match("/const VERSION = '([^']+)'/", $out, $m)) {
		$version = $m[1];
	}
	$header = "<?php\n/*\n * Bundled copy of gunsnprices/gunsnprices (MIT License: LICENSE in the gunsnprices folder) for the GunsNPrices OpenCart extension.\n"
		. " * Changed by tools/vendor-parser.php: the namespace GunsNPrices was renamed to $prefix.\n */\n";
	$out = preg_replace('/^<\?php\s*\n/', $header, $out, 1);
	file_put_contents("$dest/$rel", $out);
}

foreach (['calibers', 'models', 'entities', 'lowercase', 'source'] as $name) {
	$json = file_get_contents("$src/data/$name.json");
	if (!is_array(json_decode($json, true))) {
		fwrite(STDERR, "Bad JSON: data/$name.json\n");
		exit(1);
	}
	file_put_contents("$dest/data/$name.json", $json);
}
foreach (['LICENSE', 'LICENSE-DATA'] as $f) {
	copy("$src/$f", "$dest/$f");
}
file_put_contents(
	"$dest/README.txt",
	"gunsnprices $version, PHP title parser for firearms and ammunition.\n"
	. "Source: https://github.com/gunsnprices/gunsnprices-php (Composer: gunsnprices/gunsnprices)\n\n"
	. "Copied by tools/vendor-parser.php with one change: the namespace GunsNPrices\\... became\n"
	. "$prefix\\... so this copy cannot clash with another extension that ships the same library.\n\n"
	. "Code: MIT License (LICENSE). Data files data/models.json and data/calibers.json: CC BY 4.0,\n"
	. "GunsNPrices (https://gunsnprices.com), unchanged (LICENSE-DATA).\n"
);

echo "Vendored gunsnprices $version into shared/vendor/gunsnprices (" . count($php_files) . " PHP files)\n";
