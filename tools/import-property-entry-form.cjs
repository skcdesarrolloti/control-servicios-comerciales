// Import only field definitions and mappings; never execute scripts from form exports.
const fs = require('node:fs');
const path = require('node:path');
const [normalPath, reservedPath] = process.argv.slice(2);
if (!normalPath || !reservedPath) throw new Error('Provide both JetFormBuilder JSON exports.');
function conditions(items) {
  const groups = [[]];
  for (const item of items || []) {
    if (item.or_operator) groups.push([]);
    else if (item.field) groups.at(-1).push({ field: item.field, operator: item.operator, value: item.value });
  }
  return groups;
}
function parse(file) {
  const source = JSON.parse(fs.readFileSync(file, 'utf8'));
  const fields = [], derived = {}, stack = [];
  let section = 'Datos de ingreso';
  const tokens = source.post_content.matchAll(/<!--\s*(\/?)wp:jet-forms\/([\w-]+)(?:\s+(\{[\s\S]*?\}))?\s*(\/?)-->/g);
  for (const token of tokens) {
    const [, closing, type, json] = token;
    if (closing) { if (type === 'conditional-block') stack.pop(); continue; }
    const attrs = json ? JSON.parse(json) : {};
    if (type === 'conditional-block') { stack.push(conditions(attrs.conditions)); continue; }
    if (type === 'heading-field') { section = String(attrs.label || '').replace(/<[^>]*>/g, '').trim(); continue; }
    if (type === 'hidden-field') {
      if (attrs.value?.groups?.length) derived[attrs.name] = attrs.value.groups.map(group => ({ when: conditions(group.conditions), value: group.to_set }));
      continue;
    }
    if (!attrs.name || type === 'submit-field') continue;
    fields.push({ name: attrs.name, type: type.replace('-field', ''), label: String(attrs.label || attrs.name).replace(/<[^>]*>/g, '').trim(), section, required: !!attrs.required,
      multiple: !!attrs.multiple || type === 'checkbox-field', source: attrs.field_options_from || '', glossary: Number(attrs.glossary_id || 0), query: attrs.generator_field || '', taxonomy: attrs.field_options_tax || '', when: JSON.parse(JSON.stringify(stack)) });
  }
  const actions = JSON.parse(source.meta_input._jf_actions);
  const post = actions.find(action => action.type === 'insert_post').settings.insert_post;
  const cct = actions.find(action => action.settings?.insert_custom_content_type?.type === 'inmuebles').settings.insert_custom_content_type;
  return { fields, derived, post_map: post.fields_map, post_defaults: post.default_meta, cct_map: cct.fields_map, cct_defaults: cct.default_fields };
}
const normal = parse(normalPath), reserved = parse(reservedPath);
for (const field of reserved.fields) if (!normal.fields.some(item => item.name === field.name)) normal.fields.splice(normal.fields.findIndex(item => item.name === 'desea_aviso') + 1, 0, { ...field, reserved_only: true });
normal.derived = { ...normal.derived, ...reserved.derived };
normal.post_map['presenta-aviso'] = 'presenta-aviso';
normal.cct_map.presenta_aviso_text = 'presenta_aviso';
const destination = path.join(__dirname, '../resources/forms/property-entry.json');
fs.mkdirSync(path.dirname(destination), { recursive: true });
fs.writeFileSync(destination, JSON.stringify(normal, null, 2) + '\n');
console.log(`Imported ${normal.fields.length} fields with conditional visibility and post/CCT mappings.`);
