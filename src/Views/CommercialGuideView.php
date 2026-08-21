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
    <div id="scm-guide-modal" class="scm-go commercial-guide" role="dialog" aria-modal="true" aria-labelledby="commercial-guide-title" aria-hidden="true">
      <div class="scm-go-dialog">
        <div class="scm-go-header">
          <div>
            <span class="commercial-kicker">Guía operativa</span>
            <h3 id="commercial-guide-title"><i class="fas fa-book-open" aria-hidden="true"></i> Estados comerciales</h3>
          </div>
          <button type="button" class="scm-go-close" id="scm-close-guide" aria-label="Cerrar guía">&times;</button>
        </div>
        <div class="scm-go-body commercial-guide-body">
          <?php foreach (CommercialStatusCatalog::buckets() as $key => $bucket): ?>
            <section class="commercial-guide-group commercial-guide-group--<?php echo esc_attr($key); ?>">
              <header>
                <h4><?php echo esc_html($bucket['label']); ?></h4>
                <p><?php echo esc_html($bucket['description']); ?></p>
              </header>
              <div class="commercial-guide-grid">
                <?php foreach ($bucket['statuses'] as $status): ?>
                  <article class="commercial-guide-card">
                    <span class="commercial-status-dot" aria-hidden="true"></span>
                    <div>
                      <h5><?php echo esc_html($status); ?></h5>
                      <p><?php echo esc_html($descriptions[$status] ?? 'Estado del flujo comercial.'); ?></p>
                    </div>
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
