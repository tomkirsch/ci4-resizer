# GD Image Resizer

## Installation

Create the config file `app/Config/ResizerConfig.php`

```
<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class ResizerConfig extends BaseConfig
{
	public bool $useCache = TRUE; // turning this off may cause performance issues
	public bool $allowUpscale = FALSE; // whether to allow upscaling images, recommended FALSE (note this could break a server if a user requests a very large image)
	public int $maxSize = 0; // max output size for all images, set to 0 to disable

	public string $rewriteSegment = 'imagerez'; // this "folder" lets htaccess know to rewrite the request to Resizer controller. must match your .htaccess file regex
	// RewriteRule ^imagerez\/(.+)-([0-9]+)\.(.+) resizer/read?file=$1&size=$2&ext=$3 [NC,QSA]
	public string $rewriteSizeSep = '-'; // separator from base file name and requested size. must match your .htaccess file regex

	public string $realImagePath = ROOTPATH . '/public'; // real path to source images. this can be a private folder.
	public string $resizerCachePath = ROOTPATH . '/writable/resizercache'; // path to store cached image files
	public bool $addBaseUrl = TRUE; // whether to add base_url() to the output of publicFile()

	public int $ttl = 60 * 60 * 24 * 7; // clean cached images older than this (seconds)
	public float $randomCleanChance = 0.01; // chance of cleaning old images on each image request (default at 10%). set to 0 to disable
	public ?string $cacheControlHeader = 'public, max-age=2592000'; // Cache-Control header for browser caching (defaults is 30 days)

	public array $pictureDefaultBreakpoints = [576, 768, 992, 1200, 1400]; // default sizes for picture element, based off bootstrap breakpoints
	public array $pictureDefaultDprs = [1, 2]; // default device pixel ratios for dpr mode
	public string $pictureDefaultSourceExt = '.jpg'; // default source extension for picture element
	public string $pictureDefaultDestExt = ''; // default output extension for picture element. leave empty to use source extension by default.
	public bool $pictureDefaultLazy = FALSE; // default lazy loading for picture element
	public string $pictureDefaultLowRes = 'pixel64'; // low quality image placeholder: 'pixel64' (transparent pixel), 'first', 'last', 'custom', or supply the name to be appended to the file option
	public string $pictureNewlines = "\n"; // newlines for picture element output, set to '' for minified output
}
```

Create the service in `app/Config/Services.php`

```
	public static function resizer($config = null, bool $getShared = TRUE): \Tomkirsch\Resizer\Resizer
    {
        $config = $config ?? new ResizerConfig();
        return $getShared ? static::getSharedInstance('resizer', $config) : new \Tomkirsch\Resizer\Resizer($config);
    }
```

Add the routes in `app/Config/Routes.php`

```
$routes->get('resizer/read', '\Tomkirsch\Resizer\ResizerController::read'); // expects these GET params: file, size, sourceExt, destExt, (dpr)
$routes->get('resizer/cleandir', '\Tomkirsch\Resizer\ResizerController::cleandir'); // pass ?force=1 to delete all files regardless of age
$routes->get('resizer/cleanfile', '\Tomkirsch\Resizer\ResizerController::cleanfile'); // pass ?file=filename.ext to delete all versions of a single file
```

Modify `public/.htaccess` to use the resizer controller:

```
	# Image resizer
	# img/some-folder/filename-1024.jpg.webp
	RewriteRule ^img\/(.+)-([0-9]+)([.]\w+)([.]\w+)? resizer/read?file=$1&size=$2&sourceExt=$3&destExt=$4 [L,NC,QSA]
```

Add cache dir to `.gitignore`:

```
writable/resizercache/*
```

If you get a page not found, change $uriProtocol to use PATH_INFO in app\Config\App.php (`public string $uriProtocol = 'PATH_INFO';`) or .env (`app.uriProtocol = 'PATH_INFO'`)

## Usage

Use the `Resizer::publicFile()` method to display links to the files.

```
<?= img([
	'src'=>Config\Services::resizer()->publicFile('kitten-src', 600),
]) ?>
```

Use the picture utility to automate breakpoints:

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
