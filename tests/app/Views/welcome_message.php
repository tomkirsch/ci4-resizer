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
	<p>a simple 300px image</p>
	<?= img([
		'src' => Config\Services::resizer()->publicFile('kitten-src', 300, '.jpg'),
		'style' => 'display: block; margin: 1rem 0;',
	]) ?>
	<hr>
	<p>a 300px image with 2x DPR support using GET query string (should be 600px on hires browser)</p>
	<img style="display: block; margin: 1rem 0;" srcset="
		<?= Config\Services::resizer()->publicFile('kitten-src', 300, '.jpg') ?>, 
		<?= Config\Services::resizer()->publicFile('kitten-src', 300, '.jpg') ?>?dpr=2 2x">
	<hr>
	<p>a 3000px request (server-side upscale prevention, returns the source file's original width OR config's $maxSize)</p>
	<?= img([
		'src' => Config\Services::resizer()->publicFile('kitten-src', 3000, '.jpg'),
		'style' => 'display: block; margin: 1rem 0; max-width: 300px; height: auto;',
	]) ?>
	<hr>
	<p>Picture utility. Makes an image at the default breakpoints, with the smallest as an LQIP.</p>
	<?php
	$out = Config\Services::resizer()->picture(
		[ 	// <picture> attr
			'class' => 'my-picture',
		],
		[	// <img> attr
			'alt' => 'kitten picture',
			'style' => 'display: block; margin: 1rem 0; max-width: 100%; height: auto;',
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
	<p>Picture utility. Makes an image at the given breakpoints, and if screen is &gt;= 600, use a 390px image (with DPR support). Note that subsequent sources are discarded once a valid media query has been satisfied.</p>
	<?php
	$out = Config\Services::resizer()->picture(
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
	);
	print $out;
	print '<br><small><pre>' . htmlentities($out) . '</small></pre>';
	?>

</body>

</html>