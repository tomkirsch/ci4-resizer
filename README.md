# GD Image Resizer

## Installation

Create the service in Config/Services.php

```
	public static function resizer($config = null, bool $getShared = TRUE): \Tomkirsch\Resizer\Resizer
    {
        $config = $config ?? new \Tomkirsch\Resizer\ResizerConfig();
        return $getShared ? static::getSharedInstance('resizer', $config) : new \Tomkirsch\Resizer\Resizer($config);
    }
```

Create your resizer controller:

```
<?php

namespace App\Controllers;

use CodeIgniter\Controller;

// note we don't use BaseController here
class Resizer extends Controller
{
	// output image to browser
	public function read()
	{
		$imageFile = $this->request->getGet('file');
		if (empty($imageFile)) throw new \Exception('No file given!');
		$size = $this->request->getGet('size');
		if (empty($size)) throw new \Exception('No size given!');
		$ext = $this->request->getGet('ext');
		if (empty($ext)) throw new \Exception('No ext given!');
		if (substr($ext, 0, 1) !== '.') $ext = '.' . $ext;
		// generate cache file and spit out the actual image
		service('resizer')->read($imageFile, intval($size), $ext);
		// exit the script
		exit;
	}

	// cleanup cache for given file
	public function cleanfile()
	{
		$file = $this->request->getGet('file');
		if (empty($imageFile)) throw new \Exception('No file given!');
		$parts = explode('.', $file);
		service('resizer')->cleanFile($parts[0], '.' . $parts[1]);
		print 'cache cleaned for ' . $file . ' ' . anchor('', 'Back to home');
	}

	// cleanup all cache
	public function cleandir($force = FALSE)
	{
		service('resizer')->cleanDir((bool) $force);
		print 'cache cleaned ' . anchor('', 'Back to home');
	}
}

```

Modify .htaccess to use the resizer controller. Ensure characters match `$resizerConfig->rewriteSegment` and `$resizerConfig->rewriteSizeSep`

```
	# Image resizer
	# imagerez/some-folder/filename-1024.jpg
	RewriteRule ^imagerez\/(.+)-([0-9]+)\.(.+) resizer/read?file=$1&size=$2&ext=$3 [NC,QSA]
```

Add cache dir to .gitignore:

```
writable/resizercache/*
```

If you get a page not found, change $uriProtocol to use PATH_INFO in Config\App.php (`public string $uriProtocol = 'PATH_INFO';`) or .env (`app.uriProtocol = 'PATH_INFO')

If you get a routing error, change $autoRoute in Config\Routing.php (`public bool $autoRoute = true;`) or .env (`routing.autoRoute = true`)

## Usage

Use public URLs for your images.

```
<?= img([
	'src'=>base_url(service('resizer')->publicFile('kitten-src', 600, '.jpg')),
]) ?>
```
