<?php if ($pager['pages'] > 1): ?>
    <nav class="pagination" aria-label="Pagination">
        <?php for ($number = 1; $number <= $pager['pages']; $number++): ?>
            <a class="<?= $number === $pager['page'] ? 'current' : '' ?>" href="<?= e(url('index.php?' . http_build_query(array_merge($_GET, ['page_num' => $number])))) ?>"><?= $number ?></a>
        <?php endfor; ?>
    </nav>
<?php endif; ?>
