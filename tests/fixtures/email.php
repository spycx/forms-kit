<?php
/**
 * @var string $name
 * @var string $email
 * @var string $message
 * @var string $messageHtml
 * @var string $date
 * @var string $logoCid
 * @var list<array{name: string, label: string, value: string, html: string, multiline: bool}> $fields
 */
?>
<h1><?= $name ?></h1>
<p class="email"><?= $email ?></p>
<div class="message"><?= $messageHtml ?></div>
<p class="date"><?= $date ?></p>
<img src="cid:<?= $logoCid ?>">
<?php foreach ($fields as $f): ?>
<dt><?= $f['label'] ?></dt><dd><?= $f['multiline'] ? $f['html'] : $f['value'] ?></dd>
<?php endforeach; ?>
