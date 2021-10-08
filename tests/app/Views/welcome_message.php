<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title>Welcome to CodeIgniter 4!</title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<link rel="shortcut icon" type="image/png" href="/favicon.ico"/>
</head>
<body>
<?= img([
	'src'=>base_url(service('resizer')->publicFile('kitten-src', 600, '.jpg')),
]) ?>
</body>
</html>
