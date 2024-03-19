<?php

namespace App\Controllers;

use CodeIgniter\Controller;

// note we don't use BaseController here
class Resizer extends Controller
{
	// output image to browser
	public function read()
	{
		$request = \Config\Services::request();
		$imageFile = $request->getGet('file');
		if (empty($imageFile)) throw new \Exception('No file given!');
		$size = $request->getGet('size');
		if (empty($size)) throw new \Exception('No size given!');
		$sourceExt = $request->getGet('sourceExt');
		if (empty($sourceExt)) throw new \Exception('No source extention given!');
		$destExt = $request->getGet('destExt') ?? $sourceExt;

		// read the device pixel ratio
		$dpr = $request->getGet('dpr') ?? 1;
		$size = floor(intval($size) * floatval($dpr));

		// generate cache file and spit out the actual image
		\Config\Services::resizer()->read($imageFile, $size, $sourceExt, $destExt);
		// exit the script
		exit;
	}

	// cleanup cache for given file
	public function cleanfile()
	{
		$request = \Config\Services::request();
		$file = $request->getGet('file');
		if (empty($imageFile)) throw new \Exception('No file given!');
		$parts = explode('.', $file);
		\Config\Services::resizer()->cleanFile($parts[0], '.' . $parts[1]);
		print 'cache cleaned for ' . $file . ' ' . anchor('', 'Back to home');
	}

	// cleanup all cache
	public function cleandir($force = FALSE)
	{
		\Config\Services::resizer()->cleanDir((bool) $force);
		print 'cache cleaned ' . anchor('', 'Back to home');
	}
}
