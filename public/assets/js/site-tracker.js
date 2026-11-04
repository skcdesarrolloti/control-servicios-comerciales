(function () {
  var script = document.currentScript || {};
  var config = window.GDATracker || {};
  function repairedSearch() {
    if (window.location.search) return window.location.search;
    var marker = '/utm_source=';
    var href = window.location.href || '';
    var index = href.indexOf(marker);
    if (index === -1) return '';
    return '?' + href.slice(index + 1).split('#')[0];
  }

  var params = new URLSearchParams(window.location.search || repairedSearch() || '');
  var UTM_STORAGE_KEY = 'gda:lastUtm';
  var JUNE_2026_CAMPAIGN = 'esencia-inmobiliaria-edicion-junio-2026';
  // Copia de dashboard-marketing public/assets/tracker.js; solo cambia a dónde se envían las visitas: el panel
  // comercial (public/tracker-hit.php, dos carpetas arriba de este archivo). Si este mismo archivo se sirve desde la
  // carpeta del panel de marketing ya apagado (legacy/dashboard-marketing), envía a su index.php, que las reenvía aquí.
  var scriptSrc = script.src || window.location.href;
  var endpoint = script.dataset && script.dataset.endpoint
    ? script.dataset.endpoint
    : (scriptSrc.indexOf('/dashboard-marketing/') !== -1
      ? new URL('../index.php?action=gda-register-hit', scriptSrc)
      : new URL('../../tracker-hit.php', scriptSrc)).toString();

  function read(name) {
    if (script.dataset && script.dataset[name] !== undefined) return script.dataset[name];
    if (config[name] !== undefined) return config[name];
    return '';
  }

  function meta(name) {
    var node = document.querySelector('meta[name="gda:' + name + '"], meta[property="gda:' + name + '"]');
    return node ? node.getAttribute('content') || '' : '';
  }

  function domValue(selector, attr) {
    var node = document.querySelector(selector);
    return node ? node.getAttribute(attr) || '' : '';
  }

  function normalizeCampaign(value) {
    var raw = (value || '').trim();
    if (!raw) return '';
    var key = raw.toLowerCase().replace(/\+/g, ' ').replace(/[_\s]+/g, '-').replace(/%20/g, '-');
    if (
      key === 'revista-esencia-junio-2026' ||
      key === 'revista--esencia-junio-2026' ||
      key === 'esencia-junio' ||
      key === 'esencia-inmobiliaria-junio-2026' ||
      key === 'esencia-inmobiliaria-edicion-junio-2026-visitas' ||
      key === 'rv-esencia-junio-26-2026-visitasjunio-2026'
    ) {
      return JUNE_2026_CAMPAIGN;
    }
    return raw;
  }

  function readStoredUtm() {
    try {
      return JSON.parse(sessionStorage.getItem(UTM_STORAGE_KEY) || '{}') || {};
    } catch (error) {
      return {};
    }
  }

  function currentUtm() {
    var campaign = normalizeCampaign(params.get('utm_campaign') || '');
    var source = params.get('utm_source') || '';
    var medium = params.get('utm_medium') || '';
    if (!campaign && (source || medium) && window.location.pathname.indexOf('/esencia-inmobiliaria-edicion-junio-2026/') !== -1) {
      campaign = JUNE_2026_CAMPAIGN;
    }
    var stored = readStoredUtm();
    var utm = {
      source: source || stored.source || '',
      medium: medium || stored.medium || '',
      campaign: campaign || normalizeCampaign(stored.campaign || '')
    };
    if (source || medium || campaign) {
      try {
        sessionStorage.setItem(UTM_STORAGE_KEY, JSON.stringify(utm));
      } catch (error) {}
    }
    return utm;
  }

  function objectId() {
    return read('objectId') || read('object_id') || meta('object_id') || domValue('[data-gda-object-id]', 'data-gda-object-id') || 0;
  }

  function objectRef() {
    return read('objectRef') || read('object_ref') || meta('object_ref') || domValue('[data-gda-object-ref]', 'data-gda-object-ref') || '';
  }

  function basePayload(extra) {
    var id = objectId();
    var utm = currentUtm();
    var slug = extra.slug || read('slug') || utm.campaign || 'general';
    if ((read('isProperty') === '1' || read('isProperty') === 'true' || Number(id) > 0) && slug === 'general') {
      slug = 'inmueble_view';
    }

    return Object.assign({
      slug: slug,
      event_type: 'view',
      url: window.location.href,
      referrer: document.referrer || '',
      object_id: id,
      object_ref: objectRef(),
      user_id: read('userId') || read('user_id') || 0,
      utm_source: utm.source,
      utm_medium: utm.medium,
      utm_campaign: utm.campaign
    }, extra || {});
  }

  function send(payload) {
    var data = new FormData();
    Object.keys(payload).forEach(function (key) {
      if (payload[key] !== undefined && payload[key] !== null) data.append(key, payload[key]);
    });

    if (navigator.sendBeacon && navigator.sendBeacon(endpoint, data)) return;
    fetch(endpoint, {
      method: 'POST',
      body: data,
      mode: 'cors',
      credentials: 'omit',
      keepalive: true
    }).catch(function () {});
  }

  window.gda_send_hit = function (payload) {
    send(basePayload(payload || {}));
  };

  window.gda_track_event = function (label, metaRef) {
    send(basePayload({
      slug: 'event_click',
      event_type: 'event',
      event_label: label || '',
      object_ref: metaRef || objectRef()
    }));
  };

  window.gda_track_page = function (slug) {
    send(basePayload({ slug: slug || 'general', event_type: 'view' }));
  };

  if (!script.dataset || script.dataset.auto !== 'false') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () { send(basePayload({})); });
    } else {
      send(basePayload({}));
    }
  }
})();
