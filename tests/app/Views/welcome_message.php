<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<title>Welcome to CodeIgniter 4!</title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<link rel="shortcut icon" type="image/png" href="/favicon.ico" />
</head>

<body>
	<p><?= anchor('resizer/cleandir/1', 'Force clean the cache') ?></p>
	<p>300px</p>
	<?= img([
		'src' => base_url(service('resizer')->publicFile('kitten-src', 300, '.jpg')),
		'style' => 'display: block; margin: 1rem 0;',
	]) ?>
	<hr>
	<p>300px 2x DPR using GET query string (should be 600px on hires browser)</p>
	<img style="display: block; margin: 1rem 0;" srcset="<?= base_url(service('resizer')->publicFile('kitten-src', 300, '.jpg')) ?>?dpr=1 1x, <?= base_url(service('resizer')->publicFile('kitten-src', 300, '.jpg')) ?>?dpr=2 2x">
	<hr>
	<p>3000px (upscale prevention)</p>
	<?= img([
		'src' => base_url(service('resizer')->publicFile('kitten-src', 3000, '.jpg')),
		'style' => 'display: block; margin: 1rem 0; max-width: 300px; height: auto;',
	]) ?>
	<hr>
	<p>Picture utility. Makes an image at the default breakpoints, with the smallest as an LQIP.</p>
	<?php
	$out = service('resizer')->picture(
		[ 	// <picture> attr
			'class' => 'my-picture',
		],
		[	// <img> attr
			'alt' => 'kitten picture',
		],
		[	// options
			'file' => 'kitten-src',
			'mode' => 'screenwidth',
			'lowres' => 'first',
		]
	);
	print $out;
	print '<br><small><pre>' . htmlentities($out) . '</small></pre>';
	?>
	<hr>
	<p>Picture utility. Makes an image at the given breakpoints, and if screen is &gt;= 600, use a 390px image (with DPR support). Note that subsequent sources are discarded once a valid media query has been satisfied. A base-64 pixel is used for the LQIP.</p>
	<?php
	$out = service('resizer')->picture(
		[ 	// <picture> attr
			'class' => 'my-picture',
		],
		[	// <img> attr
			'alt' => 'kitten picture',
		],
		[	// options
			'file' => 'kitten-src',
			'mode' => 'dpr',
			'screenwidths' => [576, 768, 992],
			'lowres' => 'pixel64',
		],
		// additional <source>s. These take priority.
		['media' => '(min-width: 600px)', 'screenwidths' => [390]]
	);
	print $out;
	print '<br><small><pre>' . htmlentities($out) . '</small></pre>';
	?>

</body>

</html>