<?php
/**
 * Builds the two installable packages:
 *
 *   dist/gunsnprices_cpr.ocmod.zip      OpenCart 4.x (Extensions > Installer). The file name is the extension code
 *                                       and must stay exactly this: OpenCart 4 names the extension folder after it.
 *   dist/gunsnprices_cpr_oc3.ocmod.zip  OpenCart 3.0.x (Extensions > Installer), files under upload/.
 *
 * Usage: php tools/build.php
 *
 * The shared library (shared/: Calculator, Settings, Repository, Service and the bundled parser) goes into
 * system/library/gunsnprices_cpr/ of each package. Every PHP file is linted. Entries are sorted and get a fixed
 * timestamp, so the same sources give the same zip.
 *
 * Dev-only file, not shipped in the packages.
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}

$root = dirname(__DIR__);
$build = $root . '/build';
$dist = $root . '/dist';
$version = json_decode(file_get_contents($root . '/oc4/install.json'), true)['version'];
$mtime = mktime(0, 0, 0, 11, 11, 2026);

function gnp_build_rmtree(string $dir): void {
	if (!is_dir($dir)) {
		return;
	}

	foreach (scandir($dir) as $f) {
		if ($f === '.' || $f === '..') {
			continue;
		}

		$p = "$dir/$f";
		is_dir($p) && !is_link($p) ? gnp_build_rmtree($p) : unlink($p);
	}

	rmdir($dir);
}

/** Copies a tree, skipping dot files. */
function gnp_build_copy(string $from, string $to): void {
	if (is_file($from)) {
		@mkdir(dirname($to), 0755, true);
		copy($from, $to);

		return;
	}

	foreach (scandir($from) as $f) {
		if ($f[0] === '.') {
			continue;
		}

		gnp_build_copy("$from/$f", "$to/$f");
	}
}

/** All files under a directory, relative, sorted. */
function gnp_build_files(string $dir, string $prefix = ''): array {
	$out = [];

	foreach (scandir($dir) as $f) {
		if ($f[0] === '.') {
			continue;
		}

		if (is_dir("$dir/$f")) {
			$out = array_merge($out, gnp_build_files("$dir/$f", $prefix . $f . '/'));
		} else {
			$out[] = $prefix . $f;
		}
	}

	sort($out);

	return $out;
}

function gnp_build_zip(string $dir, string $file, int $mtime): int {
	@unlink($file);

	$zip = new ZipArchive();

	if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
		fwrite(STDERR, "Cannot create $file\n");
		exit(1);
	}

	$files = gnp_build_files($dir);

	foreach ($files as $rel) {
		$zip->addFile("$dir/$rel", $rel);

		if (method_exists($zip, 'setMtimeName')) {
			$zip->setMtimeName($rel, $mtime);
		}
	}

	$zip->close();

	return count($files);
}

gnp_build_rmtree($build);
@mkdir($dist, 0755, true);

$shared = ['autoload.php', 'src', 'vendor'];

// OpenCart 4: zip root = extension/gunsnprices_cpr/
gnp_build_copy("$root/oc4", "$build/oc4");

foreach ($shared as $item) {
	gnp_build_copy("$root/shared/$item", "$build/oc4/system/library/gunsnprices_cpr/$item");
}

copy("$root/LICENSE", "$build/oc4/LICENSE.txt");
copy("$root/README.md", "$build/oc4/README.md");
copy("$root/CHANGELOG.md", "$build/oc4/CHANGELOG.md");

// OpenCart 3: zip root has upload/
gnp_build_copy("$root/oc3", "$build/oc3");

foreach ($shared as $item) {
	gnp_build_copy("$root/shared/$item", "$build/oc3/upload/system/library/gunsnprices_cpr/$item");
}

copy("$root/LICENSE", "$build/oc3/LICENSE.txt");
copy("$root/README.md", "$build/oc3/README.md");
copy("$root/CHANGELOG.md", "$build/oc3/CHANGELOG.md");

// Lint
$php = PHP_BINARY;
$errors = 0;

foreach (['oc4', 'oc3'] as $target) {
	foreach (gnp_build_files("$build/$target") as $rel) {
		if (substr($rel, -4) === '.php') {
			exec(escapeshellarg($php) . ' -l ' . escapeshellarg("$build/$target/$rel") . ' 2>&1', $out, $code);

			if ($code !== 0) {
				fwrite(STDERR, implode("\n", $out) . "\n");
				$errors++;
			}

			$out = [];
		}
	}
}

if ($errors) {
	fwrite(STDERR, "$errors PHP files have syntax errors\n");
	exit(1);
}

$n4 = gnp_build_zip("$build/oc4", "$dist/gunsnprices_cpr.ocmod.zip", $mtime);
$n3 = gnp_build_zip("$build/oc3", "$dist/gunsnprices_cpr_oc3.ocmod.zip", $mtime);

printf("Version %s\n", $version);
printf("dist/gunsnprices_cpr.ocmod.zip      OpenCart 4.x   %d files, %d bytes, sha256 %s\n", $n4, filesize("$dist/gunsnprices_cpr.ocmod.zip"), hash_file('sha256', "$dist/gunsnprices_cpr.ocmod.zip"));
printf("dist/gunsnprices_cpr_oc3.ocmod.zip  OpenCart 3.0.x %d files, %d bytes, sha256 %s\n", $n3, filesize("$dist/gunsnprices_cpr_oc3.ocmod.zip"), hash_file('sha256', "$dist/gunsnprices_cpr_oc3.ocmod.zip"));
