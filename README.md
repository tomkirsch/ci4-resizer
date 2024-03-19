# GD Image Resizer

## Installation

Create the config file in Config/ResizerConfig.php

```
<?php

namespace App\Config;

class ResizerConfig extends \Tomkirsch\Resizer\ResizerConfig
{
	public bool $useCache = TRUE; // turning this off may cause performance issues
	public bool $allowUpscale = FALSE; // whether to allow upscaling images, recommended FALSE (note this could break a server if a user requests a very large image)
	public int $maxSize = 0; // max output size for all images, set to 0 to disable

	public string $rewriteSegment = 'imagerez'; // this "folder" lets htaccess know to rewrite the request to Resizer controller. must match your .htaccess file regex
	// RewriteRule ^imagerez\/(.+)-([0-9]+)\.(.+) resizer/read?file=$1&size=$2&ext=$3 [NC,QSA]
	public string $rewriteSizeSep = '-'; // separator from base file name and requested size. must match your .htaccess file regex

	public string $realImagePath = ROOTPATH . '/public'; // real path to source images
	public string $resizerCachePath = ROOTPATH . '/writable/resizercache'; // path to store cached image files

	public int $ttl = 60 * 60 * 24 * 7; // clean cached images older than this (seconds)
	public int $randomCleanChance = 100; // library will auto clean cache folder upon file read - helps clean cache of deleted images
}

```

Create the service in Config/Services.php

```
	public static function resizer($config = null, bool $getShared = TRUE): \Tomkirsch\Resizer\Resizer
    {
        $config = $config ?? new ResizerConfig();
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

		// read the device pixel ratio
		$dpr = $this->request->getGet('dpr') ?? 1;
		$size = floor(intval($size) * floatval($dpr));

		// generate cache file and spit out the actual image
		service('resizer')->read($imageFile, $size, $ext);
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
	'src'=>base_url(Config\Services::resizer()->publicFile('kitten-src', 600, '.jpg')),
]) ?>
```

Use the picture utility to automate breakpoints

```
Config\Services::resizer()->picture(
		[ 	// <picture> attr
			'class' => 'my-picture',
		],
		[	// <img> attr
			'alt' => 'kitten picture',
		],
		[	// options
			'file' => 'kitten-src',
			'breakpoints' => [576, 768, 992], // custom breakpoints
			'lowres' => 'first',
		],
		// additional <source>s. These take priority.
		['media' => '(min-width: 600px)', 'breakpoints' => [390]] // show a 390px image if screen is 600px or larger. This will also support DPR (2x)
	)
```
