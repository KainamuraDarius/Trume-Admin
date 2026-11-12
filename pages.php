<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login();

// Which fields each public page actually displays, and the page's address on the website.
const PAGE_LAYOUT = [
    'home' => ['label' => 'Home', 'url' => '', 'fields' => ['hero_eyebrow', 'hero_heading', 'hero_body', 'intro_label', 'intro_heading', 'intro_body']],
    'about' => ['label' => 'About Us', 'url' => 'about.php', 'fields' => ['hero_eyebrow', 'hero_heading', 'intro_label', 'intro_heading', 'intro_body']],
    'solutions' => ['label' => 'Our Solutions', 'url' => 'solutions.php', 'fields' => ['hero_eyebrow', 'hero_heading', 'intro_label', 'intro_heading', 'intro_body']],
    'truehome' => ['label' => 'Truehome', 'url' => 'truehome.php', 'fields' => ['hero_heading', 'hero_body', 'intro_label', 'intro_heading', 'intro_body']],
    'partners' => ['label' => 'Our Partners', 'url' => 'partners.php', 'fields' => ['hero_eyebrow', 'hero_heading', 'intro_label', 'intro_heading', 'intro_body']],
    'insights' => ['label' => 'Insights', 'url' => 'insights.php', 'fields' => ['hero_eyebrow', 'hero_heading', 'intro_label', 'intro_heading', 'intro_body']],
    'contact' => ['label' => 'Contact Us', 'url' => 'contact.php', 'fields' => ['hero_eyebrow', 'hero_heading', 'intro_label', 'intro_heading', 'intro_body']],
];
const FIELD_LABELS = [
    'hero_eyebrow' => ['Small label above the main heading', false],
    'hero_heading' => ['Main heading (top of the page)', false],
    'hero_body' => ['Text under the main heading', true],
    'intro_label' => ['Section label', false],
    'intro_heading' => ['Section heading', false],
    'intro_body' => ['Section text', true],
];

$slug = (string) ($_GET['page'] ?? '');
$selected = null;
if (isset(PAGE_LAYOUT[$slug])) {
    $get = db()->prepare('SELECT * FROM pages WHERE slug = ?');
    $get->execute([$slug]);
    $selected = $get->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $slug = (string) ($_POST['slug'] ?? '');
    if (!isset(PAGE_LAYOUT[$slug])) {
        redirect('pages.php');
    }
    $columns = array_merge(['page_title', 'meta_description'], PAGE_LAYOUT[$slug]['fields']);
    $values = array_map(static fn ($column) => trim((string) ($_POST[$column] ?? '')), $columns);
    if ($values[0] === '' || trim((string) ($_POST['hero_heading'] ?? '')) === '') {
        flash('notice', 'The browser tab title and main heading cannot be empty.');
        redirect('pages.php?page=' . rawurlencode($slug));
    }
    $assignments = implode(', ', array_map(static fn ($column) => "$column = ?", $columns));
    db()->prepare("UPDATE pages SET $assignments, updated_at = CURRENT_TIMESTAMP WHERE slug = ?")
        ->execute([...$values, $slug]);
    log_activity('updated', 'page: ' . $slug);
    flash('notice', 'Saved. The change is live on the website now.');
    redirect('pages.php?page=' . rawurlencode($slug));
}

$updated = array_column(db()->query('SELECT slug, updated_at FROM pages')->fetchAll(), 'updated_at', 'slug');
$pageTitle = $selected ? 'Edit ' . PAGE_LAYOUT[$selected['slug']]['label'] . ' page' : 'Pages';
$activePage = 'pages';
require __DIR__ . '/partials/header.php';
?>
<?php if (!$selected): ?>
<section class="panel"><h2>Page content</h2><p>Choose a page to edit its main heading, introduction and how it appears in Google and link previews.</p>
<div class="content-grid"><?php foreach (PAGE_LAYOUT as $key => $page): ?><a class="content-card" href="pages.php?page=<?= e($key) ?>"><strong><?= e($page['label']) ?></strong><small>Updated <?= e($updated[$key] ?? 'never') ?></small></a><?php endforeach; ?></div></section>
<?php else: $layout = PAGE_LAYOUT[$selected['slug']]; ?>
<div class="page-actions"><a href="pages.php">← All pages</a><a class="button secondary" href="<?= e(public_site_url($layout['url'])) ?>" target="_blank" rel="noopener">View page on website ↗</a></div>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="slug" value="<?= e($selected['slug']) ?>">
<section class="panel"><h2>On the page</h2><p><?= formatting_hint(true) ?></p><div class="form-grid">
<?php foreach ($layout['fields'] as $field): [$label, $long] = FIELD_LABELS[$field]; ?>
<?php if ($long): ?><label class="full"><?= e($label) ?><textarea name="<?= e($field) ?>" rows="5"><?= e($selected[$field]) ?></textarea></label>
<?php else: ?><label class="<?= $field === 'hero_heading' || $field === 'intro_heading' ? 'full' : '' ?>"><?= e($label) ?><input name="<?= e($field) ?>" value="<?= e($selected[$field]) ?>"<?= $field === 'hero_heading' ? ' required' : '' ?>></label><?php endif; ?>
<?php endforeach; ?>
</div></section>
<section class="panel"><h2>Google and link previews</h2><div class="form-grid">
<label class="full">Browser tab title<input name="page_title" value="<?= e($selected['page_title']) ?>" maxlength="70" required></label>
<label class="full">Description <span class="hint">Shown under the link in Google and in WhatsApp/LinkedIn previews. About 150 characters.</span><textarea name="meta_description" rows="3" maxlength="300"><?= e($selected['meta_description']) ?></textarea></label>
</div></section>
<div class="form-actions"><button class="button" type="submit">Save and publish</button></div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php';
