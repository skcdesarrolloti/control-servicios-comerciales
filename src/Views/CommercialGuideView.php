<?php

declare(strict_types=1);

namespace SCM\Views;

use SCM\Commercial\CommercialStatusCatalog;

final class CommercialGuideView
{
  public static function render(): string
  {
    $descriptions = CommercialStatusCatalog::descriptions();
    ob_start();
?>
    <div id="scm-guide-modal" class="fixed inset-0 z-50 items-center justify-center p-4 bg-inverse-surface/60 backdrop-blur-sm commercial-guide scm-go" role="dialog" aria-modal="true" aria-labelledby="commercial-guide-title" aria-hidden="true">
      <div class="bg-surface-container-lowest rounded-2xl shadow-2xl w-full max-w-4xl max-h-[88vh] overflow-hidden flex flex-col relative scm-go-dialog">
        <!-- Header -->
        <div class="p-space-lg bg-surface-container-low flex items-center justify-between border-b border-surface-container scm-go-header">
          <div class="flex items-center gap-space-xs">
            <span class="p-2 rounded-xl bg-primary-container text-on-surface">
              <span class="material-symbols-outlined text-[20px]">menu_book</span>
            </span>
            <div>
              <span class="font-label-sm text-secondary uppercase font-semibold">Guía Operativa</span>
              <h3 id="commercial-guide-title" class="font-headline-sm text-headline-sm text-on-surface">Estados Comerciales del Flujo</h3>
            </div>
          </div>
          <button type="button" class="w-8 h-8 rounded-full hover:bg-surface-container-high text-secondary flex items-center justify-center cursor-pointer transition-colors scm-go-close" id="scm-close-guide" aria-label="Cerrar guía">
            <span class="material-symbols-outlined text-[20px]">close</span>
          </button>
        </div>

        <!-- Body -->
        <div class="overflow-y-auto p-space-lg space-y-space-lg flex-1 scm-go-body commercial-guide-body">
          <?php foreach (CommercialStatusCatalog::buckets() as $key => $bucket): ?>
            <?php if ($key === 'mis_tickets') continue; ?>
            <section class="space-y-space-sm commercial-guide-group commercial-guide-group--<?php echo esc_attr($key); ?>">
              <div class="border-b border-surface-container pb-1">
                <h4 class="font-headline-sm text-[16px] text-on-surface font-semibold"><?php echo esc_html($bucket['label']); ?></h4>
                <p class="font-body-sm text-body-sm text-on-surface-variant"><?php echo esc_html($bucket['description']); ?></p>
              </div>
              <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-space-sm commercial-guide-grid">
                <?php foreach ($bucket['statuses'] as $status): ?>
                  <article class="p-space-md bg-surface-container-low rounded-xl border border-surface-container space-y-1 hover:border-outline-variant transition-colors commercial-guide-card">
                    <div class="flex items-center gap-2">
                      <span class="w-2.5 h-2.5 rounded-full bg-primary flex-shrink-0" aria-hidden="true"></span>
                      <h5 class="font-label-md text-label-md font-semibold text-on-surface"><?php echo esc_html($status); ?></h5>
                    </div>
                    <p class="font-body-sm text-[12px] text-on-surface-variant leading-relaxed"><?php echo esc_html($descriptions[$status] ?? 'Estado del flujo comercial.'); ?></p>
                  </article>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
  }
}
