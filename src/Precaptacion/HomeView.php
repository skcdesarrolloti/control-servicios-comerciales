<?php

declare(strict_types=1);

namespace SCM\Precaptacion;

final class HomeView
{
    public static function render(array $s, string $baseUrl): string
    {
        if (empty($s['available'])) return '';
        $link = static fn(array $filters): string => rtrim($baseUrl, '/') . '/index.php?' . http_build_query(['tab'=>'precaptacion'] + $filters);
        $metrics = [
            ['Por llamar','por_llamar',['precaptaciones_estado_contacto'=>'por_llamar']],
            ['Sin respuesta','sin_respuesta',['precaptaciones_estado_contacto'=>'no_contesto']],
            ['Llamadas vencidas','vencidas',['precaptaciones_agenda'=>'vencidas']],
            ['Tareas por crear','pendiente_tarea',['precaptaciones_estado_contacto'=>'pendiente_tarea']],
        ];
        $scope = ($s['scope'] ?? '') === 'global' ? 'Total global del equipo' : 'Mis registros asignados';
        ob_start(); ?>
        <section class="w-full px-margin" data-precap-home>
          <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-space-lg border border-slate-100">
            <div class="flex flex-wrap items-center justify-between gap-space-md mb-space-md"><div><p class="text-label-sm font-semibold uppercase tracking-wider text-secondary">Actividad de precaptaciones · <?php echo esc_html($scope); ?></p><h2 class="font-headline-sm text-headline-sm text-on-surface">Llamadas y oportunidades pendientes</h2></div><button type="button" data-commercial-open-advisory="precaptaciones" class="rounded-xl bg-primary-container px-space-md py-2.5 font-semibold text-on-surface">Ver pendientes (<?php echo (int) ($s['pendientes'] ?? 0); ?>)</button></div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-space-md">
              <?php foreach ($metrics as [$label,$key,$filters]): ?><a href="<?php echo esc_url($link($filters)); ?>" class="rounded-2xl border border-slate-200 bg-surface-container-low p-space-md text-on-surface hover:bg-surface-container transition-colors"><p class="font-label-sm text-secondary"><?php echo esc_html($label); ?></p><strong class="text-[28px] font-bold <?php echo $key === 'vencidas' ? 'text-error' : 'text-on-surface'; ?>"><?php echo (int) ($s[$key] ?? 0); ?></strong><span class="block text-label-sm mt-2">Gestionar →</span></a><?php endforeach; ?>
            </div>
            <div class="flex flex-wrap gap-space-md mt-space-md text-label-sm text-secondary"><a href="<?php echo esc_url($link(['precaptaciones_agenda'=>'hoy'])); ?>">Hoy: <strong><?php echo (int) ($s['hoy'] ?? 0); ?></strong></a><a href="<?php echo esc_url($link(['precaptaciones_agenda'=>'proximas'])); ?>">Por vencer en 24 horas: <strong><?php echo (int) ($s['por_vencer'] ?? 0); ?></strong></a><a href="<?php echo esc_url($link(['precaptaciones_estado_contacto'=>'seguimiento'])); ?>">En seguimiento: <?php echo (int) ($s['seguimiento'] ?? 0); ?></a><a href="<?php echo esc_url($link(['precaptaciones_estado_contacto'=>'cerrada'])); ?>">Cerradas: <?php echo (int) ($s['cerradas'] ?? 0); ?></a><a href="<?php echo esc_url($link(['precaptaciones_estado_contacto'=>'convertida'])); ?>">Convertidas: <?php echo (int) ($s['convertidas'] ?? 0); ?></a></div>
          </div>
        </section>
        <div class="fixed inset-0 z-50 items-center justify-center p-4 sm:p-6 bg-[#061D49]/50 backdrop-blur-md commercial-modal commercial-advisory-modal" role="dialog" aria-modal="true" aria-labelledby="precap-home-title" aria-hidden="true" data-commercial-advisory-modal="precaptaciones" data-auto-open="<?php echo ($s['vencidas'] ?? 0) > 0 ? '1' : '0'; ?>">
          <div class="bg-surface-container-lowest w-full max-w-xl max-h-[94vh] rounded-3xl shadow-modal border border-slate-100 overflow-hidden flex flex-col relative" role="document">
            <header class="shrink-0 p-6 bg-error-container/30 border-b border-error/10 flex items-center justify-between"><div><p class="text-error font-semibold uppercase text-label-sm">Aviso de pendientes</p><h2 id="precap-home-title" class="font-headline-sm text-headline-sm text-on-surface">Gestión de precaptaciones</h2><p class="text-label-sm text-secondary"><?php echo esc_html($scope); ?></p></div><button type="button" class="rounded-full bg-surface-container-low p-2 text-secondary" data-commercial-close-advisory aria-label="Cerrar aviso de precaptaciones">×</button></header>
            <div class="p-6 overflow-y-auto min-h-0 space-y-4"><div class="grid grid-cols-2 gap-3"><div class="rounded-2xl bg-error-container p-4 text-error"><strong class="block text-[28px]"><?php echo (int) ($s['vencidas'] ?? 0); ?></strong>Llamadas vencidas</div><div class="rounded-2xl bg-surface-container-low p-4 text-on-surface"><strong class="block text-[28px]"><?php echo (int) ($s['pendientes'] ?? 0); ?></strong>Registros pendientes</div></div>
              <p class="text-body-sm text-secondary">Prioriza las llamadas vencidas y las oportunidades aprobadas que todavía no tienen tarea. Cada registro conserva sus llamadas, intentos y responsable.</p>
              <?php foreach (($s['items'] ?? []) as $item): ?><a class="block rounded-xl border border-slate-200 p-3 text-body-sm text-on-surface" href="<?php echo esc_url($link(['precaptaciones_id'=>$item['id'],'abrir'=>'detalles'])); ?>"><strong>#<?php echo (int) $item['id']; ?> · <?php echo esc_html($item['contact'] ?: 'Contacto por identificar'); ?></strong><span class="block text-label-sm text-secondary"><?php echo esc_html($item['status']); ?> · <?php echo esc_html($item['owner']); ?></span><?php if ($item['next']): ?><span class="block text-label-sm <?php echo $item['overdue'] ? 'text-error' : 'text-secondary'; ?>">Próxima llamada: <?php echo esc_html(date('d/m/Y H:i', strtotime($item['next']))); ?></span><?php elseif (in_array($item['status'], ['En seguimiento','No contestó'], true)): ?><span class="block text-label-sm text-error">Sin próxima llamada programada</span><?php endif; ?></a><?php endforeach; ?>
              <?php if (($s['scope'] ?? '') === 'global'): ?><details><summary class="text-body-sm font-semibold text-on-surface cursor-pointer">Desglose por responsable</summary><?php foreach (($s['advisors'] ?? []) as $advisor): ?><p class="text-label-sm text-secondary py-2"><?php echo esc_html($advisor['name']); ?>: <?php echo (int) $advisor['pending']; ?> pendientes · <?php echo (int) $advisor['overdue']; ?> vencidas</p><?php endforeach; ?></details><?php endif; ?>
            </div>
            <footer class="shrink-0 p-5 bg-surface-container-low border-t border-slate-200 flex justify-end gap-3"><button type="button" data-commercial-close-advisory class="rounded-xl px-4 py-2 text-secondary">Cerrar</button><a class="rounded-xl bg-primary-container px-4 py-2 font-semibold text-on-surface" href="<?php echo esc_url($link(['precaptaciones_pendientes'=>'1'])); ?>">Gestionar precaptaciones →</a></footer>
          </div>
        </div>
        <?php return (string) ob_get_clean();
    }
}
