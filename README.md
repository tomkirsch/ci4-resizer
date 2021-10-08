# GD Image Resizer

## Installation

Create the service in Config/Services.php
```
	public static function resizer($config = null, bool $getShared=TRUE){
		$config = $config ?? new \Tomkirsch\Resizer\ResizerConfig();
		return $getShared ? static::getSharedInstance('resizer', $config) : new \Tomkirsch\Resizer\Resizer($config);
	}
```

Create your resizer controller:
```
<?php namespace App\Controllers;

use CodeIgniter\Controller;

// note we don't use BaseController here
class Resizer extends Controller{
	// output image to browser
	public function read(){
		$imageFile = $this->request->getGet('file');
		if(empty($imageFile)) throw new \Exception('No file given!');
		$size = $this->request->getGet('size');
		if(empty($size)) throw new \Exception('No size given!');
		$ext = $this->request->getGet('ext');
		if(empty($ext)) throw new \Exception('No ext given!');
		if(substr($ext, 0, 1) !== '.') $ext = '.'.$ext;
		// generate cache file and spit out the actual image
		// this will exit the script!
		service('resizer')->read($imageFile, intval($size), $ext);
	}
	
	// cleanup cache for given file
	public function cleanfile(){
		$file = $this->request->getGet('file');
		if(empty($imageFile)) throw new \Exception('No file given!');
		$parts = explode('.', $file);
		service('resizer')->cleanFile($parts[0], '.'.$parts[1]);
	}
	
	// cleanup all cache
	public function cleandir($force=FALSE){
		service('resizer')->cleanDir((bool) $force);
	}
}
```

Modify .htaccess to use the resizer controller. Ensure characters match `$resizerConfig->rewriteSegment` and `$resizerConfig->rewriteSizeSep`
```
	# Image resizer
	# imagerez/some-folder/filename-1024.jpg
	RewriteRule ^imagerez\/(.+)-([0-9]+)\.(.+) resizer/read?file=$1&size=$2&ext=$3 [NC,QSA]
```

If you get a page not found, change your config to use PATH_INFO in app\Config\App.php:
```
	public $uriProtocol = 'PATH_INFO';
```

Add cache dir to .gitignore:
```
writable/resizercache/*
````

## Usage

Use public URLs for your images.
```
<?= img([
	'src'=>base_url(service('resizer')->publicFile('kitten-src', 600, '.jpg')),
]) ?>
```
