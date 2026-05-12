<?php
/**
 * editPage.php — renders the client-data editing form.
 *
 * Expected variables injected by the parent (index.php):
 *   $structure  : array  (decoded lp_structure.json)
 *   $clientData : array  (decoded client_data.json, may be empty)
 */

if (!isset($structure) || !is_array($structure)) {
    echo '<div class="alert alert-danger">サイト構造データが読み込めませんでした。</div>';
    return;
}

/** @var string $sourceIndustry 元サイト業種（index.php で Haiku により1回だけ推定） */
/** @var list<string> $suggestions */
if (!isset($sourceIndustry)) {
    $sourceIndustry = '';
}
if (!isset($suggestions) || !is_array($suggestions)) {
    $suggestions = [];
}

$meta       = $structure['meta']      ?? [];
$sections   = $structure['sections']  ?? [];
$clientMeta = ($clientData['meta']     ?? []);
$clientElems = ($clientData['elements'] ?? []);

$sectionCount  = count($sections);
$elementCount  = $structure['total_elements'] ?? array_sum(array_column($sections, 'element_count'));

/**
 * rollback_src (例: assets/rollback/foo.jpg) を serve_workspace_output.php 経由の
 * プレビュー URL に変換する。$outputWsPrefix は index.php から継承。
 */
$rollbackPreviewUrl = static function (string $rollbackSrc) use ($outputWsPrefix): string {
    if ($rollbackSrc === '') {
        return '';
    }
    $wsPrefix  = rtrim((string) ($outputWsPrefix ?? ''), '/');
    $absPath   = $wsPrefix . '/' . ltrim($rollbackSrc, '/');
    return 'store/serve_workspace_output.php?p=' . rawurlencode($absPath);
};
?>

  <form id="clientDataForm">

  <!-- ヒント: 空欄 = 元テキスト自動使用 -->
  <div class="alert alert-light border d-flex align-items-start gap-2 py-2 px-3 mb-3" style="font-size:.8rem;line-height:1.4">
    <i class="bi bi-lightbulb text-warning mt-1 flex-shrink-0"></i>
    <span>
      <strong>入力欄が空欄の場合、保存時に元サイトのテキストをそのまま使用します。</strong><br>
      薄い斜体で表示されているテキストはプレースホルダー（元テキストのプレビュー）です。クリックして上書きできます。
    </span>
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
          <i class="bi bi-chevron-down lp-sec-toggle"></i>
          <i class="bi <?= $secIcon ?>"></i>
          <strong><?= $secLabel ?></strong>
          <span class="badge bg-white text-dark ms-1"><?= $secType ?></span>
        </span>
        <span class="badge bg-white text-dark"><?= $elemCount ?>要素</span>
      </div>
      <div id="collapse_<?= $secId ?>" class="collapse show">
        <div class="card-body">
          <?php
            $bgHints = $section['css_background_hints'] ?? [];
            // (inline style) は elements[] に background_image 要素として入るため、ここでは CSS クラス系のみ表示
            $bgHintsFiltered = array_filter($bgHints, static fn($h) => ($h['token'] ?? '') !== '(inline style)');
          ?>
          <?php if ($bgHintsFiltered !== []): ?>
            <div class="row g-3 mb-3">
              <?php foreach ($bgHintsFiltered as $bgIdx => $h): ?>
                <?php
                  $tok           = (string) ($h['token'] ?? '');
                  $origBgSrc     = (string) ($h['url'] ?? '');
                  $rollbackBgSrc = (string) ($h['rollback_src'] ?? '');
                  $bgElemId      = 'css_bg_' . $section['id'] . '_' . $bgIdx;
                  $bgClientEl    = $clientElems[$bgElemId] ?? [];
                  $currentBgSrc  = (string) ($bgClientEl['src'] ?? '');
                  // プレビュー: 置き換え済み > rollback proxy（外部 URL はホットリンク拒否の可能性）> 外部URL
                  if ($currentBgSrc !== '') {
                      $previewBgSrc = $currentBgSrc;
                  } elseif ($rollbackBgSrc !== '') {
                      $previewBgSrc = $rollbackPreviewUrl($rollbackBgSrc);
                  } else {
                      $previewBgSrc = $origBgSrc;
                  }
                  // モーダル左ペイン: rollback パス（JS が proxy URL に変換）> 元URL
                  $leftPaneBgSrc = $rollbackBgSrc ?: $origBgSrc;
                  $bgElemIdAttr  = htmlspecialchars($bgElemId, ENT_QUOTES, 'UTF-8');
                ?>
                <div class="col-md-6">
                  <div class="p-3 rounded border border-1 h-100" style="background:#fafafa">
                    <div class="d-flex align-items-center gap-1 mb-2">
                      <i class="bi bi-aspect-ratio text-secondary"></i>
                      <span class="small fw-semibold">CSS背景: <code class="fs-6"><?= htmlspecialchars($tok, ENT_QUOTES, 'UTF-8') ?></code></span>
                      <?php if ($rollbackBgSrc !== ''): ?>
                        <span class="badge bg-light text-secondary ms-auto" title="ロールバック保存済み"><i class="bi bi-shield-check"></i></span>
                      <?php endif; ?>
                    </div>
                    <?php if ($previewBgSrc): ?>
                      <div class="mb-2 text-center">
                        <img src="<?= htmlspecialchars($previewBgSrc, ENT_QUOTES, 'UTF-8') ?>"
                             alt="CSS背景プレビュー"
                             class="img-fluid rounded border"
                             style="max-height:120px; object-fit:contain;"
                             data-preview-for="<?= $bgElemIdAttr ?>">
                      </div>
                    <?php endif; ?>
                    <label class="form-label small">画像URL</label>
                    <div class="input-group input-group-sm mb-1">
                      <input type="url" class="form-control"
                             data-lp-id="<?= $bgElemIdAttr ?>"
                             data-lp-field="src"
                             placeholder="<?= htmlspecialchars($origBgSrc, ENT_QUOTES, 'UTF-8') ?>"
                             value="<?= htmlspecialchars($currentBgSrc, ENT_QUOTES, 'UTF-8') ?>">
                      <button type="button"
                              class="btn btn-outline-secondary lp-open-image-replace"
                              data-lp-id="<?= $bgElemIdAttr ?>"
                              data-lp-rollback-src="<?= htmlspecialchars($rollbackBgSrc, ENT_QUOTES, 'UTF-8') ?>"
                              data-lp-original-src="<?= htmlspecialchars($leftPaneBgSrc, ENT_QUOTES, 'UTF-8') ?>"
                              title="モーダルで差し替え">
                        <i class="bi bi-images"></i>
                      </button>
                    </div>
                    <p class="mb-0 text-muted" style="font-size:.72em">生成時に <code><?= htmlspecialchars($tok, ENT_QUOTES, 'UTF-8') ?></code> の background-image を上書きします</p>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if (empty($section['elements'])): ?>
            <p class="text-muted small">このセクションに編集可能な要素はありません。</p>
          <?php else: ?>
            <div class="row g-3">
              <?php foreach ($section['elements'] as $elem): ?>
                <?php
                  $elemId        = htmlspecialchars($elem['id'], ENT_QUOTES);
                  $elemType      = $elem['type'] ?? 'text';
                  $elemLabel     = htmlspecialchars($elem['label'] ?? $elem['id'], ENT_QUOTES);
                  $typeLabel     = htmlspecialchars($elem['type_label'] ?? $elemType, ENT_QUOTES);
                  $origText      = $elem['original_text'] ?? '';
                  $origSrc       = $elem['original_src']  ?? '';
                  $rollbackSrc   = (string) ($elem['rollback_src'] ?? '');
                  $origHref      = $elem['original_href'] ?? '';
                  $clientElem    = $clientElems[$elem['id']] ?? [];
                  $currentText   = $clientElem['text'] ?? '';
                  $currentSrc    = $clientElem['src']  ?? '';
                  $currentHref   = $clientElem['href'] ?? '';
                  // モーダル左ペイン: rollback パス（JS が proxy URL に変換）> original_src
                  $leftPaneSrc   = $rollbackSrc ?: $origSrc;
                  // プレビュー: 置き換え済み > rollback proxy（外部URLはホットリンク拒否の可能性）> 外部URL
                  if ($currentSrc !== '') {
                      $previewSrc = $currentSrc;
                  } elseif ($rollbackSrc !== '') {
                      $previewSrc = $rollbackPreviewUrl($rollbackSrc);
                  } else {
                      $previewSrc = $origSrc;
                  }

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
                      <?php elseif ($elemType === 'background_image'): ?>
                        <i class="bi bi-card-image text-secondary"></i>
                      <?php elseif ($elemType === 'button'): ?>
                        <i class="bi bi-arrow-right-circle text-danger"></i>
                      <?php else: ?>
                        <i class="bi bi-link-45deg text-info"></i>
                      <?php endif; ?>
                      <span class="small fw-semibold"><?= $elemLabel ?></span>
                      <?php if ($rollbackSrc !== ''): ?>
                        <span class="badge bg-light text-secondary ms-auto" title="ロールバック保存済み"><i class="bi bi-shield-check"></i></span>
                      <?php else: ?>
                        <span class="badge bg-light text-secondary ms-auto">&lt;<?= htmlspecialchars($elem['tag'] ?? '', ENT_QUOTES) ?>&gt;</span>
                      <?php endif; ?>
                    </div>

                    <?php if ($elemType === 'image' || $elemType === 'background_image'): ?>
                      <!-- Image / background_image: show current image + URL field -->
                      <?php if ($previewSrc): ?>
                        <div class="mb-2 text-center">
                          <img src="<?= htmlspecialchars($previewSrc, ENT_QUOTES) ?>"
                               alt="preview"
                               class="img-fluid rounded border"
                               style="max-height:120px; object-fit:contain;"
                               data-preview-for="<?= $elemId ?>">
                        </div>
                      <?php endif; ?>
                      <label class="form-label small"><?= $elemType === 'background_image' ? '背景画像URL' : '画像URL' ?></label>
                      <div class="input-group input-group-sm mb-2">
                        <input type="url" class="form-control"
                               data-lp-id="<?= $elemId ?>"
                               data-lp-field="src"
                               placeholder="<?= htmlspecialchars($origSrc, ENT_QUOTES) ?>"
                               value="<?= htmlspecialchars($currentSrc, ENT_QUOTES) ?>">
                        <button type="button"
                                class="btn btn-outline-secondary lp-open-image-replace"
                                data-lp-id="<?= $elemId ?>"
                                data-lp-rollback-src="<?= htmlspecialchars($rollbackSrc, ENT_QUOTES) ?>"
                                data-lp-original-src="<?= htmlspecialchars($leftPaneSrc, ENT_QUOTES) ?>"
                                title="モーダルで差し替え">
                          <i class="bi bi-images"></i>
                        </button>
                      </div>
                      <?php if ($elemType === 'image'): ?>
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
                          $memoOrig = (string) ($elem['image_embedded_text_memo'] ?? '');
                          $memoVal = array_key_exists('image_embedded_text_memo', $clientElem)
                            ? (string) $clientElem['image_embedded_text_memo']
                            : $memoOrig;
                          if (trim($memoVal) === '') {
                            $memoVal = trim((string) ($origText ?? ''));
                          }
                        ?>
                        <label class="form-label small">画像内テキスト（メモ・解析結果）</label>
                        <textarea class="form-control form-control-sm mb-1" rows="3"
                                  data-lp-id="<?= $elemId ?>"
                                  data-lp-field="image_embedded_text_memo"
                                  placeholder="解析時に Vision で抽出した文言が入ります。編集・追記して保存できます。"><?= htmlspecialchars($memoVal, ENT_QUOTES) ?></textarea>
                        <button type="button"
                                class="btn btn-sm btn-outline-primary mb-2 lp-refine-image-from-memo"
                                data-lp-id="<?= $elemId ?>">
                          メモの文言で画像を再生成（UI/合成・テキスト焼き込み）
                        </button>
                        <?php if ($memoOrig !== '' && $memoVal !== $memoOrig): ?>
                          <div class="form-text text-muted mb-2 small" style="white-space:pre-wrap">解析時の元メモ：<?= htmlspecialchars(mb_substr($memoOrig, 0, 200), ENT_QUOTES) ?><?= mb_strlen($memoOrig) > 200 ? '…' : '' ?></div>
                        <?php endif; ?>
                        <?php if ($wrapHref !== '' || $wrapTargetOrig !== ''): ?>
                          <?php $__ws = (string) ($elem['href_scope'] ?? ''); ?>
                          <?php if ($__ws !== '' && $__ws !== 'none'): ?>
                            <div class="mb-1 small">
                              <span class="badge bg-light text-dark border"><?= htmlspecialchars($__ws, ENT_QUOTES, 'UTF-8') ?></span>
                              <?php if (!empty($elem['internal_relative_href'])): ?>
                                <span class="text-success ms-1">クローン内 <code><?= htmlspecialchars((string) $elem['internal_relative_href'], ENT_QUOTES, 'UTF-8') ?></code></span>
                              <?php endif; ?>
                              <?php if (!empty($elem['href_redirect_check'])): ?>
                                <span class="text-muted ms-1">HEAD: <code><?= htmlspecialchars((string) $elem['href_redirect_check'], ENT_QUOTES, 'UTF-8') ?></code></span>
                              <?php endif; ?>
                            </div>
                          <?php endif; ?>
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
                      <?php else: ?>
                        <!-- background_image: 注記のみ -->
                        <p class="mb-0 text-muted" style="font-size:.72em">
                          インライン <code>style</code> の <code>background-image</code> を上書きします。
                          置き換えなければロールバック画像をそのまま利用。
                        </p>
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
                      <?php
                        $__scope = (string) ($elem['href_scope'] ?? '');
                      ?>
                      <?php if ($__scope !== '' && $__scope !== 'none'): ?>
                        <div class="mb-1 small">
                          <span class="badge bg-light text-dark border"><?= htmlspecialchars($__scope, ENT_QUOTES, 'UTF-8') ?></span>
                          <?php if (!empty($elem['internal_relative_href'])): ?>
                            <span class="text-success ms-1">クローン内 <code><?= htmlspecialchars((string) $elem['internal_relative_href'], ENT_QUOTES, 'UTF-8') ?></code></span>
                          <?php endif; ?>
                          <?php if (!empty($elem['href_redirect_check'])): ?>
                            <span class="text-muted ms-1">HEAD: <code><?= htmlspecialchars((string) $elem['href_redirect_check'], ENT_QUOTES, 'UTF-8') ?></code></span>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
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
