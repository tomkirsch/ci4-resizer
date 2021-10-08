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
		service('resizer')->read($imageFile, intval($size), $ext);
		// exit the script
		exit;
	}
	
	// cleanup cache for given file
	public function cleanfile(){
		$file = $this->request->getGet('file');
		if(empty($imageFile)) throw new \Exception('No file given!');
		$parts = explode('.', $file);
		service('resizer')->cleanFile($parts[0], '.'.$parts[1]);
		print 'cache cleaned';
	}
	
	// cleanup all cache
	public function cleandir($force=FALSE){
		service('resizer')->cleanDir((bool) $force);
		print 'cache cleaned';
	}
}