<?php
/**
 * editPage.php — renders the client-data editing form.
 *
 * Expected variables injected by the parent (index.php):
 *   $structure  : array  (decoded lp_structure.json)
 *   $clientData : array  (decoded client_data.json, may be empty)
 */

if (!isset($structure) || !is_array($structure)) {
    echo '<div class="alert alert-danger">LP構造データが読み込めませんでした。</div>';
    return;
}

require_once dirname(__DIR__) . '/lib/suggest_industries.php';
$suggestBundle  = lp_reverse_suggest_industries_from_structure($structure);
$sourceIndustry = (string) ($suggestBundle['source_industry'] ?? '');
$suggestions    = $suggestBundle['suggestions'] ?? [];
if (!is_array($suggestions)) {
    $suggestions = [];
}

$meta       = $structure['meta']      ?? [];
$sections   = $structure['sections']  ?? [];
$clientMeta = ($clientData['meta']     ?? []);
$clientElems = ($clientData['elements'] ?? []);

$sectionCount  = count($sections);
$elementCount  = $structure['total_elements'] ?? array_sum(array_column($sections, 'element_count'));
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h5 class="mb-1 fw-bold text-primary">
      <i class="bi bi-pencil-square me-2"></i>コンテンツ編集
    </h5>
    <p class="text-muted small mb-0">
      解析元：<a href="<?= htmlspecialchars($structure['source_url'] ?? '', ENT_QUOTES) ?>" target="_blank" class="text-decoration-none">
        <?= htmlspecialchars($structure['source_url'] ?? '', ENT_QUOTES) ?>
      </a>
      <span class="ms-3 badge bg-secondary"><?= $sectionCount ?>セクション</span>
      <span class="ms-1 badge bg-secondary"><?= $elementCount ?>要素</span>
    </p>
  </div>
  </div>

  <form id="clientDataForm">

  <!-- AI テキスト自動置換（text_replace_ui + industry_suggest_and_batch） -->
  <div id="ai-text-replace-panel" class="card shadow-sm mb-3 border-primary">
    <div class="card-header bg-primary text-white d-flex align-items-center gap-2 py-2 flex-wrap">
      <span><i class="bi bi-stars me-1" aria-hidden="true"></i><strong>AI テキスト自動生成</strong></span>
      <small class="ms-md-auto opacity-75">業種を入力して全テキストを一括置換（保存は従来どおり）</small>
    </div>
    <div class="card-body py-3">
      <p class="small text-muted mb-2">
        対象は <code>data-lp-field="text"</code> / <code>content</code> のみ。画像URL・リンクURLは変更しません。
      </p>
      <?php if ($sourceIndustry !== ''): ?>
        <p class="small text-muted mb-2">
          元LP業種: <strong class="text-body"><?= htmlspecialchars($sourceIndustry, ENT_QUOTES, 'UTF-8') ?></strong>
        </p>
      <?php endif; ?>
      <?php if ($suggestions !== []): ?>
        <div class="mb-2 d-flex flex-wrap gap-1" id="ai-suggest-chips">
          <?php foreach ($suggestions as $s): ?>
            <?php
              $s = (string) $s;
              if ($s === '') {
                  continue;
              }
            ?>
            <button type="button"
                    class="btn btn-sm btn-outline-primary ai-chip"
                    data-value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="row g-2 align-items-end">
        <div class="col-md-5 col-lg-4">
          <label class="form-label small mb-1" for="ai-industry">ターゲット業種 <span class="text-danger">*</span></label>
          <input type="text" id="ai-industry" class="form-control form-control-sm"
                 placeholder="例：ネイルサロン、歯科クリニック、学習塾" autocomplete="off"
                 list="ai-industry-list">
          <datalist id="ai-industry-list">
            <?php foreach ($suggestions as $s): ?>
              <?php $s = (string) $s; ?>
              <?php if ($s === '') { continue; } ?>
              <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>"></option>
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="col-md-4 col-lg-3">
          <label class="form-label small mb-1" for="ai-tone">トーン</label>
          <select id="ai-tone" class="form-select form-select-sm">
            <option value="polite">丁寧・上品</option>
            <option value="casual">カジュアル</option>
            <option value="professional">ビジネス</option>
          </select>
        </div>
        <div class="col-6 col-md-2 col-lg-2">
          <button type="button" id="ai-replace-btn" class="btn btn-sm btn-primary w-100">AI 置換</button>
        </div>
        <div class="col-6 col-md-2 col-lg-2">
          <button type="button" id="ai-replace-undo" class="btn btn-sm btn-outline-secondary w-100" hidden>元に戻す</button>
        </div>
      </div>
      <div id="ai-replace-status" class="mt-2 small text-muted" hidden></div>
    </div>
  </div>

  <!-- ===== META ===== -->
  <div class="card shadow-sm mb-3">
    <div class="card-header bg-dark text-white d-flex align-items-center gap-2 py-2">
      <i class="bi bi-info-circle-fill"></i>
      <strong>ページ情報（メタデータ）</strong>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label small fw-semibold">ページタイトル</label>
          <input type="text" class="form-control form-control-sm"
                 name="meta[title]"
                 placeholder="<?= htmlspecialchars($meta['title'] ?? '', ENT_QUOTES) ?>"
                 value="<?= htmlspecialchars($clientMeta['title'] ?? '', ENT_QUOTES) ?>">
          <?php if (!empty($meta['title'])): ?>
            <div class="form-text text-muted">元：<?= htmlspecialchars($meta['title'], ENT_QUOTES) ?></div>
          <?php endif; ?>
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-semibold">メタディスクリプション</label>
          <input type="text" class="form-control form-control-sm"
                 name="meta[description]"
                 placeholder="<?= htmlspecialchars($meta['description'] ?? '', ENT_QUOTES) ?>"
                 value="<?= htmlspecialchars($clientMeta['description'] ?? '', ENT_QUOTES) ?>">
          <?php if (!empty($meta['description'])): ?>
            <div class="form-text text-muted">元：<?= htmlspecialchars(mb_substr($meta['description'], 0, 80), ENT_QUOTES) ?>…</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ===== SECTIONS ===== -->
  <?php foreach ($sections as $secIdx => $section): ?>
    <?php
      $secId    = htmlspecialchars($section['id'], ENT_QUOTES);
      $secLabel = htmlspecialchars($section['label'], ENT_QUOTES);
      $secIcon  = htmlspecialchars($section['type_icon'] ?? 'bi-square', ENT_QUOTES);
      $secType  = htmlspecialchars($section['type_label'] ?? $section['type'], ENT_QUOTES);
      $elemCount = count($section['elements'] ?? []);

      $headerColors = [
        'hero'         => 'bg-primary',
        'nav'          => 'bg-secondary',
        'features'     => 'bg-success',
        'testimonials' => 'bg-info text-dark',
        'cta'          => 'bg-danger',
        'pricing'      => 'bg-warning text-dark',
        'faq'          => 'bg-dark',
        'footer'       => 'bg-secondary',
        'general'      => 'bg-secondary',
      ];
      $headerClass = $headerColors[$section['type']] ?? 'bg-secondary';
    ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header <?= $headerClass ?> text-white d-flex align-items-center justify-content-between py-2"
           style="cursor:pointer"
           data-bs-toggle="collapse"
           data-bs-target="#collapse_<?= $secId ?>">
        <span class="d-flex align-items-center gap-2">
          <i class="bi <?= $secIcon ?>"></i>
          <strong><?= $secLabel ?></strong>
          <span class="badge bg-white text-dark ms-1"><?= $secType ?></span>
        </span>
        <span class="badge bg-white text-dark"><?= $elemCount ?>要素 <i class="bi bi-chevron-down ms-1"></i></span>
      </div>
      <div id="collapse_<?= $secId ?>" class="collapse show">
        <div class="card-body">
          <?php if (empty($section['elements'])): ?>
            <p class="text-muted small">このセクションに編集可能な要素はありません。</p>
          <?php else: ?>
            <div class="row g-3">
              <?php foreach ($section['elements'] as $elem): ?>
                <?php
                  $elemId       = htmlspecialchars($elem['id'], ENT_QUOTES);
                  $elemType     = $elem['type'] ?? 'text';
                  $elemLabel    = htmlspecialchars($elem['label'] ?? $elem['id'], ENT_QUOTES);
                  $typeLabel    = htmlspecialchars($elem['type_label'] ?? $elemType, ENT_QUOTES);
                  $origText     = $elem['original_text'] ?? '';
                  $origSrc      = $elem['original_src']  ?? '';
                  $origHref     = $elem['original_href'] ?? '';
                  $clientElem   = $clientElems[$elem['id']] ?? [];
                  $currentText  = $clientElem['text'] ?? '';
                  $currentSrc   = $clientElem['src']  ?? '';
                  $currentHref  = $clientElem['href'] ?? '';

                  $colClass = ($elemType === 'paragraph') ? 'col-12' : 'col-md-6';
                ?>
                <div class="<?= $colClass ?>">
                  <div class="p-3 rounded border border-1 h-100" style="background:#fafafa">
                    <div class="d-flex align-items-center gap-1 mb-2">
                      <?php if ($elemType === 'heading'): ?>
                        <i class="bi bi-type-h1 text-primary"></i>
                      <?php elseif ($elemType === 'paragraph'): ?>
                        <i class="bi bi-paragraph text-success"></i>
                      <?php elseif ($elemType === 'image'): ?>
                        <i class="bi bi-image text-warning"></i>
                      <?php elseif ($elemType === 'button'): ?>
                        <i class="bi bi-arrow-right-circle text-danger"></i>
                      <?php else: ?>
                        <i class="bi bi-link-45deg text-info"></i>
                      <?php endif; ?>
                      <span class="small fw-semibold"><?= $elemLabel ?></span>
                      <span class="badge bg-light text-secondary ms-auto">&lt;<?= htmlspecialchars($elem['tag'] ?? '', ENT_QUOTES) ?>&gt;</span>
                    </div>

                    <?php if ($elemType === 'image'): ?>
                      <!-- Image: show current image + URL field + alt field -->
                      <?php if ($origSrc || $currentSrc): ?>
                        <div class="mb-2 text-center">
                          <img src="<?= htmlspecialchars($currentSrc ?: $origSrc, ENT_QUOTES) ?>"
                               alt="preview"
                               class="img-fluid rounded border"
                               style="max-height:120px; object-fit:contain;"
                               data-preview-for="<?= $elemId ?>">
                        </div>
                      <?php endif; ?>
                      <label class="form-label small">画像URL</label>
                      <input type="url" class="form-control form-control-sm mb-2"
                             data-lp-id="<?= $elemId ?>"
                             data-lp-field="src"
                             placeholder="<?= htmlspecialchars($origSrc, ENT_QUOTES) ?>"
                             value="<?= htmlspecialchars($currentSrc, ENT_QUOTES) ?>">
                      <label class="form-label small">alt テキスト</label>
                      <input type="text" class="form-control form-control-sm mb-2"
                             data-lp-id="<?= $elemId ?>"
                             data-lp-field="text"
                             data-lp-type="<?= htmlspecialchars($elemType, ENT_QUOTES) ?>"
                             data-lp-label="<?= htmlspecialchars($elem['label'] ?? '', ENT_QUOTES) ?>"
                             placeholder="<?= htmlspecialchars($origText, ENT_QUOTES) ?>"
                             value="<?= htmlspecialchars($currentText, ENT_QUOTES) ?>">
                      <?php
                        $wrapHref = (string) ($elem['original_href'] ?? '');
                        $wrapTargetOrig = (string) ($elem['wrap_target'] ?? '');
                        $currentTarget = (string) ($clientElem['target'] ?? '');
                      ?>
                      <?php if ($wrapHref !== '' || $wrapTargetOrig !== ''): ?>
                        <label class="form-label small">囲みリンク先（親の &lt;a href&gt;）</label>
                        <input type="text" class="form-control form-control-sm mb-2"
                               data-lp-id="<?= $elemId ?>"
                               data-lp-field="href"
                               placeholder="<?= htmlspecialchars($wrapHref, ENT_QUOTES) ?>"
                               value="<?= htmlspecialchars($currentHref, ENT_QUOTES) ?>">
                        <label class="form-label small">target（例: _blank）</label>
                        <input type="text" class="form-control form-control-sm"
                               data-lp-id="<?= $elemId ?>"
                               data-lp-field="target"
                               placeholder="<?= htmlspecialchars($wrapTargetOrig, ENT_QUOTES) ?>"
                               value="<?= htmlspecialchars($currentTarget, ENT_QUOTES) ?>">
                      <?php endif; ?>

                    <?php elseif ($elemType === 'paragraph'): ?>
                      <!-- Paragraph: textarea -->
                      <?php if ($origText): ?>
                        <div class="form-text text-muted mb-1 small" style="white-space:pre-wrap">元：<?= htmlspecialchars(mb_substr($origText, 0, 100), ENT_QUOTES) ?><?= mb_strlen($origText) > 100 ? '…' : '' ?></div>
                      <?php endif; ?>
                      <textarea class="form-control form-control-sm"
                                rows="3"
                                data-lp-id="<?= $elemId ?>"
                                data-lp-field="text"
                                data-lp-type="<?= htmlspecialchars($elemType, ENT_QUOTES) ?>"
                                data-lp-label="<?= htmlspecialchars($elem['label'] ?? '', ENT_QUOTES) ?>"
                                placeholder="<?= htmlspecialchars($origText, ENT_QUOTES) ?>"><?= htmlspecialchars($currentText, ENT_QUOTES) ?></textarea>

                    <?php elseif ($elemType === 'button' || $elemType === 'link'): ?>
                      <!-- Button / Link: text + href -->
                      <?php if ($origText): ?>
                        <div class="form-text text-muted mb-1 small">元テキスト：<?= htmlspecialchars($origText, ENT_QUOTES) ?></div>
                      <?php endif; ?>
                      <label class="form-label small">テキスト</label>
                      <input type="text" class="form-control form-control-sm mb-2"
                             data-lp-id="<?= $elemId ?>"
                             data-lp-field="text"
                             data-lp-type="<?= htmlspecialchars($elemType, ENT_QUOTES) ?>"
                             data-lp-label="<?= htmlspecialchars($elem['label'] ?? '', ENT_QUOTES) ?>"
                             placeholder="<?= htmlspecialchars($origText, ENT_QUOTES) ?>"
                             value="<?= htmlspecialchars($currentText, ENT_QUOTES) ?>">
                      <label class="form-label small">リンク先URL</label>
                      <input type="url" class="form-control form-control-sm"
                             data-lp-id="<?= $elemId ?>"
                             data-lp-field="href"
                             placeholder="<?= htmlspecialchars($origHref, ENT_QUOTES) ?>"
                             value="<?= htmlspecialchars($currentHref, ENT_QUOTES) ?>">

                    <?php else: ?>
                      <!-- Heading or other text -->
                      <?php if ($origText): ?>
                        <div class="form-text text-muted mb-1 small">元：<?= htmlspecialchars(mb_substr($origText, 0, 60), ENT_QUOTES) ?></div>
                      <?php endif; ?>
                      <input type="text" class="form-control form-control-sm"
                             data-lp-id="<?= $elemId ?>"
                             data-lp-field="text"
                             data-lp-type="<?= htmlspecialchars($elemType, ENT_QUOTES) ?>"
                             data-lp-label="<?= htmlspecialchars($elem['label'] ?? '', ENT_QUOTES) ?>"
                             placeholder="<?= htmlspecialchars($origText, ENT_QUOTES) ?>"
                             value="<?= htmlspecialchars($currentText, ENT_QUOTES) ?>">
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

</form>
