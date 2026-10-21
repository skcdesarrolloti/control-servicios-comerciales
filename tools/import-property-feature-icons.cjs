const fs = require('node:fs');
const path = require('node:path');
const source = process.argv[2] || 'C:/Workspace/desarrollo-skc/sucasa-inmuebles-premium/src/FeatureIcons.php';
const icons = {};
for (const group of fs.readFileSync(source, 'utf8').matchAll(/'(fa-[a-z0-9-]+)'\s*=>\s*array\(([\s\S]*?)\),/g)) {
  for (const label of group[2].matchAll(/'([^']+)'/g)) icons[label[1].normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim()] = group[1];
}
if (Object.keys(icons).length < 100) throw new Error('No se pudo leer el mapa de iconos premium.');
fs.writeFileSync(path.join(__dirname, '../resources/forms/property-feature-icons.json'), JSON.stringify(icons, null, 2) + '\n');
console.log(`${Object.keys(icons).length} iconos importados del catálogo premium.`);
