/* Local photo adjustments. Only the explicitly painted pixels are changed by healing. */
"use strict";
self.onmessage = function (event) {
  try {
    var job = event.data, width = job.width, height = job.height, pixels = new Uint8ClampedArray(job.pixels), size = width * height;
    if (width < 1 || height < 1 || size > 2040000 || pixels.length !== size * 4) throw new Error("Tamaño de imagen no permitido.");
    if (job.action === "enhance") {
      var histogram = new Uint32Array(256), means = [0,0,0];
      for (var i = 0; i < pixels.length; i += 4) { var light = Math.round(0.2126 * pixels[i] + 0.7152 * pixels[i + 1] + 0.0722 * pixels[i + 2]); histogram[light]++; for (var c = 0; c < 3; c++) means[c] += pixels[i + c]; }
      var count = 0, low = 0, high = 255;
      for (i = 0; i < 256; i++) { count += histogram[i]; if (count >= size * 0.01) { low = i; break; } }
      count = 0; for (i = 255; i >= 0; i--) { count += histogram[i]; if (count >= size * 0.01) { high = i; break; } }
      var black = Math.min(32,low * .35), white = Math.max(high,black + 160), gray = (means[0] + means[1] + means[2]) / 3, avg = (means[0] * .2126 + means[1] * .7152 + means[2] * .0722) / size;
      var gamma = avg < 95 ? .83 : (avg > 185 ? 1.07 : .97);
      for (i = 0; i < pixels.length; i += 4) {
        light = .2126 * pixels[i] + .7152 * pixels[i + 1] + .0722 * pixels[i + 2];
        var normalized = Math.max(0,Math.min(1,(light - black) / (white - black))), ratio = Math.max(.65,Math.min(1.65,255 * Math.pow(normalized,gamma) / Math.max(1,light)));
        for (c = 0; c < 3; c++) { var balance = Math.max(.96,Math.min(1.04,gray / Math.max(1,means[c]))); pixels[i + c] = Math.round(pixels[i + c] * (.25 + .75 * ratio * balance)); }
      }
      var source = new Uint8ClampedArray(pixels);
      for (var y = 1; y < height - 1; y++) for (var x = 1; x < width - 1; x++) {
        i = (y * width + x) * 4;
        for (c = 0; c < 3; c++) { var surrounding = (source[i - 4 + c] + source[i + 4 + c] + source[i - width * 4 + c] + source[i + width * 4 + c]) / 4; var delta = Math.max(-10,Math.min(10,(source[i + c] - surrounding) * .3)); pixels[i + c] = source[i + c] + delta; }
      }
    } else if (job.action === "heal") {
      var mask = new Uint8Array(job.mask);
      if (mask.length !== size) throw new Error("La máscara no corresponde a la imagen.");
      var marked = 0; for (i = 0; i < size; i++) if (mask[i]) marked++;
      if (!marked) throw new Error("Pinta primero la zona que deseas retocar.");
      if (marked > size * .3) throw new Error("Retoca zonas más pequeñas, de una en una.");
      var queued = new Uint8Array(size), queue = new Int32Array(size), tail = 0;
      function neighbors(index) { var nx = index % width, ny = Math.floor(index / width); return [nx > 0 ? index - 1 : -1,nx + 1 < width ? index + 1 : -1,ny > 0 ? index - width : -1,ny + 1 < height ? index + width : -1]; }
      for (i = 0; i < size; i++) if (mask[i] && neighbors(i).some(function (n) { return n >= 0 && !mask[n]; })) { queue[tail++] = i; queued[i] = 1; }
      for (var head = 0; head < tail; head++) {
        var at = queue[head], px = at % width, py = Math.floor(at / width), totals = [0,0,0], weight = 0;
        for (var dy = -3; dy <= 3; dy++) for (var dx = -3; dx <= 3; dx++) {
          var nx = px + dx, ny = py + dy;
          if (nx < 0 || ny < 0 || nx >= width || ny >= height || (!dx && !dy)) continue;
          var sample = ny * width + nx; if (mask[sample]) continue;
          var w = 1 / (dx * dx + dy * dy); weight += w;
          for (c = 0; c < 3; c++) totals[c] += pixels[sample * 4 + c] * w;
        }
        if (weight) { for (c = 0; c < 3; c++) pixels[at * 4 + c] = totals[c] / weight; mask[at] = 0; }
        neighbors(at).forEach(function (n) { if (n >= 0 && mask[n] && !queued[n]) { queued[n] = 1; queue[tail++] = n; } });
      }
    } else throw new Error("Ajuste de imagen desconocido.");
    self.postMessage({pixels:pixels.buffer},[pixels.buffer]);
  } catch (error) { self.postMessage({error:error.message || "No se pudo procesar la imagen."}); }
};
