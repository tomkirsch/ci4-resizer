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
	public function cleanFile(){
		$file = $this->request->getGet('file');
		if(empty($imageFile)) throw new \Exception('No file given!');
		$parts = explode('.', $file);
		service('resizer')->cleanFile($parts[0], '.'.$parts[1]);
	}
	
	// cleanup all cache
	public function cleanDir($force=FALSE){
		service('resizer')->cleanDir((bool) $force);
	}
}