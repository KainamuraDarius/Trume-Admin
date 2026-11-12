<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$admin = require_login();
$uploadDirectory = uploads_directory();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $query = db()->prepare('SELECT stored_path FROM media WHERE id = ?'); $query->execute([$id]); $file = $query->fetch();
        if ($file) {
            $inUse = db()->prepare('SELECT (SELECT COUNT(*) FROM founders WHERE image_path = :p) + (SELECT COUNT(*) FROM articles WHERE image_path = :p)');
            $inUse->execute(['p' => $file['stored_path']]);
            if ((int) $inUse->fetchColumn() > 0) { flash('notice', 'This image is used by a founder or an article. Choose a different image there first, then remove it.'); redirect('media.php'); }
        }
        if ($file) { $path = dirname($uploadDirectory) . '/' . $file['stored_path']; if (is_file($path) && str_starts_with(realpath($path) ?: '', realpath($uploadDirectory) ?: '')) { unlink($path); } db()->prepare('DELETE FROM media WHERE id = ?')->execute([$id]); log_activity('deleted', 'media', $id); }
        flash('notice', 'Media file removed.'); redirect('media.php');
    }
    try {
        $stored = store_uploaded_image($_FILES['file'] ?? null, (int) $admin['id'], trim((string) ($_POST['alt_text'] ?? '')));
        if ($stored === null) { throw new RuntimeException('Choose an image to upload.'); }
    } catch (RuntimeException $exception) {
        flash('notice', $exception->getMessage()); redirect('media.php');
    }
    log_activity('uploaded', 'media'); flash('notice', 'Image uploaded. You can now choose it when editing a founder or an article.'); redirect('media.php');
}
$media = db()->query('SELECT * FROM media ORDER BY created_at DESC')->fetchAll(); $pageTitle = 'Media library'; $activePage = 'media'; require __DIR__ . '/partials/header.php';
?>
<section class="panel"><h2>Upload image</h2><p>Accepted formats: JPG, PNG, WebP and GIF. Maximum size: 8 MB. Add meaningful alternative text for accessibility.</p><form method="post" enctype="multipart/form-data" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Image file<input name="file" type="file" accept="image/jpeg,image/png,image/webp,image/gif" required></label><label>Alternative text<input name="alt_text" placeholder="Describe what the image shows"></label><div class="form-actions full"><button class="button" type="submit">Upload image</button></div></form></section>
<section class="panel"><h2>Library</h2><?php if (!$media): ?><p class="empty">No uploaded images yet.</p><?php else: ?><div class="media-grid"><?php foreach ($media as $item): ?><article class="media-card"><img src="<?= e(public_site_url($item['stored_path'])) ?>" alt="<?= e($item['alt_text']) ?>"><div><strong><?= e($item['original_name']) ?></strong><code><?= e($item['stored_path']) ?></code><small><?= e($item['mime_type']) ?> · <?= number_format((int)$item['file_size'] / 1024, 1) ?> KB</small><form method="post" onsubmit="return confirm('Permanently remove this file?');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $item['id'] ?>"><button class="link-danger" type="submit">Remove</button></form></div></article><?php endforeach; ?></div><?php endif; ?></section>
<?php require __DIR__ . '/partials/footer.php';
