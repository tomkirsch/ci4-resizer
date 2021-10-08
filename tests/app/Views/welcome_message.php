<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title>Welcome to CodeIgniter 4!</title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<link rel="shortcut icon" type="image/png" href="/favicon.ico"/>
</head>
<body><p>
<?= img([
	'src'=>base_url(service('resizer')->publicFile('kitten-src', 300, '.jpg')),
]) ?>
</p>
<p>
<?= img([
	'src'=>base_url(service('resizer')->publicFile('kitten-src', 600, '.jpg')),
]) ?>
</p>
	
	<p><?= anchor('resizer/cleandir/1', 'Force clean the cache') ?></p>
</body>
</html>
