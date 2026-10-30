const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const clients = path.resolve(__dirname, '../../../../dev/abrahamalanya');
const ts = require(path.join(clients, 'umax-frontend/node_modules/typescript'));

function load(project, file, mocks) {
  const exports = {};
  const source = fs.readFileSync(path.join(clients, project, file), 'utf8');
  const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX } }).outputText;
  vm.runInNewContext(code, { exports, URLSearchParams, require: name => {
    if (!(name in mocks)) throw Error('Unexpected import: ' + name);
    return mocks[name];
  } });
  return exports;
}

for (const [project, api] of [['umax-frontend', 'src/api/creditosPrendarios.ts'], ['umax-app', 'src/api/creditos.ts']]) {
  test(project + ': sends code, dates, advisor and states together, keeping pagination', async () => {
    let url;
    const module = load(project, api, {
      './client': { apiFetch: async input => { url = input; return {}; } },
      '../utils/formData': {},
    });
    await module.listCreditos(3, { codigo: ' C-000951 ', fechaDesembolsoDesde: '2026-10-20', fechaDesembolsoHasta: '2026-10-21', asesorId: 8, estado: ['activo', 'vencido'] });
    const params = new URLSearchParams(url.split('?')[1]);
    assert.equal(params.get('codigo'), 'C-000951');
    assert.equal(params.get('fecha_desembolso_desde'), '2026-10-20');
    assert.equal(params.get('fecha_desembolso_hasta'), '2026-10-21');
    assert.equal(params.get('asesor_id'), '8');
    assert.equal(params.get('page'), '3');
    assert.deepEqual(params.getAll('estado[]'), ['activo', 'vencido']);
    await module.listCreditos(1, { codigo: '  ' });
    assert.equal(new URLSearchParams(url.split('?')[1]).has('codigo'), false);
  });
}

const jsx = (type, props) => ({ type, props: props ?? {} });
function nodes(tree) {
  if (!tree || typeof tree !== 'object') return [];
  if (Array.isArray(tree)) return tree.flatMap(nodes);
  return [tree, ...nodes(tree.props?.children)];
}
function screen(role) {
  const slots = [];
  let index = 0;
  let focus;
  const calls = [];
  const state = initial => {
    const i = index++;
    if (!(i in slots)) slots[i] = typeof initial === 'function' ? initial() : initial;
    return [slots[i], next => { slots[i] = typeof next === 'function' ? next(slots[i]) : next; }];
  };
  const react = { useState: state, useRef: value => state(() => ({ current: value }))[0], useCallback: fn => fn, useEffect: () => {}, useLayoutEffect: () => {} };
  const roles = load('umax-app', 'src/utils/roles.ts', {});
  const module = load('umax-app', 'src/screens/creditos/CreditosListScreen.tsx', {
    react, 'react/jsx-runtime': { jsx, jsxs: jsx },
    '@react-navigation/native': { useFocusEffect: callback => { focus = callback; } },
    'react-native': { FlatList: 'FlatList', ScrollView: 'ScrollView', View: 'View', StyleSheet: { create: s => s } },
    'react-native-paper': { ActivityIndicator: 'Spinner', Button: 'Button', Dialog: Object.assign('Dialog', { Title: 'Title', ScrollArea: 'Scroll', Actions: 'Actions' }), FAB: 'FAB', IconButton: 'Icon', List: { Item: 'ListItem' }, Portal: 'Portal', Text: 'Text', TextInput: 'Input', useTheme: () => ({ colors: {} }) },
    '../../api/creditos': { listCreditos: async (...args) => { calls.push(args); return { data: { data: [], last_page: 3 } }; } },
    '../../hooks/useAuth': { useAuth: () => ({ user: role ? { roles: [{ name: role }] } : null }) },
    '../../utils/roles': roles,
    '../../utils/creditoPrendarioHierarchy': { CREDITO_ESTADO_LABELS: { activo: 'Activo', pendiente: 'Pendiente', rechazado: 'Rechazado' }, CREDITO_ESTADO_TONE: {}, TIPO_CREDITO_LABELS: { diario: 'Diario' }, tipoCreditoDisponible: () => false },
    '../../utils/format': { formatFecha: value => value, formatMonto: value => value },
    '../../components/DateField': { DateField: 'DateField' },
    '../../components/AsesorSelectField': { AsesorSelectField: 'AdvisorSelect' },
    '../../components/ClienteAutocomplete': { ClienteAutocomplete: 'ClientSelect' },
    '../../components/Pagination': { Pagination: 'Pagination' },
    '../../components/SelectField': { SelectField: 'Select' },
    '../../components/StatusChip': { StatusChip: 'Status' },
    '../../components/ErrorBanner': { ErrorBanner: 'Error' },
  });
  return {
    calls,
    focus: () => focus(),
    render: () => { index = 0; return nodes(module.CreditosListScreen({ route: { params: { variant: 'diario' } }, navigation: {} })); },
  };
}
const flush = () => new Promise(resolve => setImmediate(resolve));
for (const role of ['administrador_general', 'administrador_agencia', 'sistemas', 'asesor', 'supervisor', 'secretaria', null]) {
  test('mobile advisor visibility for ' + role, async () => {
    const h = screen(role);
    h.render(); h.focus(); await flush();
    const tree = h.render();
    const list = tree.find(n => n.type === 'FlatList');
    const row = list.props.renderItem({ item: { codigo: 'C-000951', cliente_id: 1, monto_prestamo: '500', estado: 'activo', asesor: { nombre: 'Danny', apellido: 'Vasquez' }, fecha_desembolso: '2026-10-20' } });
    const content = JSON.stringify(row.props.description());
    const allowed = ['administrador_general', 'administrador_agencia', 'sistemas'].includes(role);
    assert.equal(content.includes('Asesor:'), allowed);
    assert.equal(tree.some(n => n.type === 'AdvisorSelect'), allowed);
    assert.equal(content.includes('Desembolsado: 2026-10-20'), true);
  });
}
test('mobile code search resets pagination and clear removes the code', async () => {
  const h = screen('sistemas');
  h.render(); h.focus(); await flush();
  h.render().find(n => n.type === 'Pagination').props.onPageChange(2);
  h.render(); h.focus(); await flush();
  assert.equal(h.calls.at(-1)[0], 2);
  h.render().find(n => n.type === 'Input' && n.props.label === 'Código de crédito').props.onChangeText(' 951 ');
  h.render(); h.focus(); await flush();
  assert.equal(h.calls.at(-1)[0], 1);
  assert.equal(h.calls.at(-1)[1].codigo, '951');
  h.render().find(n => n.type === 'Button' && n.props.children === 'Limpiar').props.onPress();
  h.render(); h.focus(); await flush();
  assert.equal(h.calls.at(-1)[1].codigo, undefined);
});
