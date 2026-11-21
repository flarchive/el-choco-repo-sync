import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import Admin from 'flarum/common/extenders/Admin';

const tr = (key) => app.translator.trans('el-choco-repo-sync.admin.' + key);

export class RepoSyncPage extends ExtensionPage {
  oninit(vnode) {
    super.oninit(vnode);
    this.config = null;
    this.jobs = [];
    this.backups = [];
    this.secret = '';
    this.token = '';
    this.clearToken = false;
    this.busy = false;
    this.dirty = false;
    this.message = '';
    this.error = '';
    this.backup = null;
    this.load();
  }

  onremove() { this.removed = true; }

  async request(body) {
    try {
      return await app.request({ method: body ? 'POST' : 'GET', url: app.forum.attribute('apiUrl') + '/repo-sync/admin', body, errorHandler() {} });
    } catch (error) {
      throw error.response?.error || 'request_failed';
    }
  }

  async load() {
    if (this.busy) return;
    this.busy = true;
    this.error = '';
    try {
      const data = await this.request();
      if (this.removed) return;
      this.config = data.config;
      this.jobs = data.jobs;
      this.backups = data.backups;
    } catch (error) { if (!this.removed) this.error = error; }
    finally { if (!this.removed) { this.busy = false; m.redraw(); } }
  }

  async action(action, extra = {}) {
    if (this.busy || (this.dirty && action !== 'save')) return;
    this.busy = true;
    this.message = '';
    this.error = '';
    try {
      const data = await this.request({ action, ...extra });
      if (this.removed) return;
      this.config = data.config;
      this.jobs = data.jobs;
      this.backups = data.backups;
      this.dirty = false;
      if (action === 'save') { this.secret = ''; this.token = ''; this.clearToken = false; }
      this.message = action === 'save' ? 'saved' : action === 'run' ? 'processed' : 'queued';
    } catch (error) { if (!this.removed) this.error = error; }
    finally { if (!this.removed) { this.busy = false; m.redraw(); } }
  }

  set(object, key, value) { object[key] = value; this.dirty = true; this.message = ''; }

  field(label, object, key, attrs = {}) {
    return m('.Form-group', [m('label', { for: 'rs-' + (object.id || 'global') + '-' + key }, tr(label)),
      m('input.FormControl', { id: 'rs-' + (object.id || 'global') + '-' + key, value: object[key] || '', disabled: this.busy,
        oninput: event => this.set(object, key, event.target.value), ...attrs })]);
  }

  toggle(label, object, key) {
    return m('.Form-group', Switch.component({ state: !!object[key], disabled: this.busy, onchange: value => this.set(object, key, value) }, tr(label)));
  }

  add() {
    if (this.config.mappings.length >= 20) return;
    this.config.mappings.push({ id: crypto.randomUUID(), enabled: false, repo: '', branch: 'main', file: 'README.md',
      discussion: '', post: '', mode: 'section', readme: true, releases: false, prereleases: false });
    this.dirty = true;
  }

  generateSecret() {
    this.secret = Array.from(crypto.getRandomValues(new Uint8Array(32)), value => value.toString(16).padStart(2, '0')).join('');
    this.dirty = true;
  }

  async resolvePost(item) {
    if (this.busy) return;
    this.busy = true;
    this.error = '';
    try {
      const data = await this.request({ action: 'resolve', discussion: item.discussion });
      if (!this.removed) this.set(item, 'post', String(data.post));
    } catch (error) { if (!this.removed) this.error = error; }
    finally { if (!this.removed) { this.busy = false; m.redraw(); } }
  }

  async showBackup(id) {
    if (this.busy) return;
    this.busy = true;
    try {
      const data = await app.request({ url: app.forum.attribute('apiUrl') + '/repo-sync/admin', params: { backup: id }, errorHandler() {} });
      if (!this.removed) this.backup = data.backup;
    } catch { if (!this.removed) this.error = 'request_failed'; }
    finally { if (!this.removed) { this.busy = false; m.redraw(); } }
  }

  mapping(item) {
    return m('section.RepoSync-card', [
      m('.RepoSync-cardHeader', [m('h3', item.repo || tr('new_mapping')), Button.component({ className: 'Button', disabled: this.busy,
        onclick: () => { this.config.mappings = this.config.mappings.filter(row => row !== item); this.dirty = true; } }, tr('remove'))]),
      this.toggle('mapping_enabled', item, 'enabled'),
      m('.RepoSync-grid', [this.field('repo', item, 'repo', { placeholder: 'el-choco/kobalt' }), this.field('branch', item, 'branch'),
        this.field('file', item, 'file'), this.field('discussion', item, 'discussion', { inputmode: 'numeric' }), this.field('post', item, 'post', { inputmode: 'numeric' })]),
      m('p.helpText', tr('ids_help')),
      Button.component({ className: 'Button', disabled: this.busy || !/^\d+$/.test(item.discussion), onclick: () => this.resolvePost(item) }, tr('resolve')),
      this.toggle('readme', item, 'readme'),
      m('.Form-group', [m('label', { for: 'rs-mode-' + item.id }, tr('mode')),
        m('select.FormControl', { id: 'rs-mode-' + item.id, value: item.mode, disabled: this.busy, onchange: event => this.set(item, 'mode', event.target.value) },
          ['section', 'whole'].map(value => m('option', { value }, tr('mode_' + value))))]),
      m('p.helpText', tr(item.mode === 'whole' ? 'whole_help' : 'section_help')),
      m('code.RepoSync-marker', '[REPOSYNC]\n' + tr('marker_content') + '\n[/REPOSYNC]'),
      this.toggle('releases', item, 'releases'), this.toggle('prereleases', item, 'prereleases'),
      m('.RepoSync-actions', [
        Button.component({ className: 'Button Button--primary', disabled: this.busy || this.dirty || !item.enabled || !item.readme || !this.config.enabled,
          onclick: () => this.action('sync', { mapping: item.id }) }, tr('sync')),
        item.mode === 'section' && Button.component({ className: 'Button', disabled: this.busy || this.dirty || !item.enabled || !item.readme || !this.config.enabled,
          onclick: () => { if (window.confirm(String(tr('initialize_confirm')))) this.action('initialize', { mapping: item.id }); } }, tr('initialize')),
      ]),
    ]);
  }

  content() {
    return m('.container.RepoSync', [
      m('section.RepoSync-intro', [m('h2', 'Repo Sync'), m('p', tr('description')), m('p.helpText', tr('scheduler_help'))]),
      this.error && m('.Alert.Alert--error', tr('errors.' + this.error)),
      this.message && m('.Alert.Alert--success', tr(this.message)),
      !this.config ? m('p', tr('loading')) : [
        m('section.RepoSync-card', [m('h3', tr('connection')), this.toggle('enabled', this.config, 'enabled'),
          this.field('actor', this.config, 'actor', { inputmode: 'numeric' }), m('p.helpText', tr('actor_help')),
          m('.Form-group', [m('label', tr('webhook')), m('input.FormControl', { readonly: true, value: app.forum.attribute('apiUrl') + '/repo-sync/webhook' })]),
          m('.Form-group', [m('label', { for: 'rs-secret' }, tr('secret')), m('input.FormControl', { id: 'rs-secret', type: 'password', autocomplete: 'new-password', disabled: this.busy,
            value: this.secret, placeholder: tr(this.config.hasSecret ? 'secret_saved' : 'secret_needed'), oninput: event => this.set(this, 'secret', event.target.value) }),
          Button.component({ className: 'Button', onclick: () => this.generateSecret(), disabled: this.busy }, tr('generate')),
          this.secret && m('textarea.FormControl', { readonly: true, rows: 2, value: this.secret, 'aria-label': tr('generated_secret') })]),
          m('p.helpText', tr('secret_help')),
          m('.Form-group', [m('label', { for: 'rs-token' }, tr('token')), m('input.FormControl', { id: 'rs-token', type: 'password', autocomplete: 'new-password', disabled: this.busy,
            value: this.token, placeholder: tr(this.config.hasToken ? 'token_saved' : 'token_optional'), oninput: event => this.set(this, 'token', event.target.value) })]),
          this.toggle('clear_token', this, 'clearToken'), m('p.helpText', tr('token_help')),
        ]),
        ...this.config.mappings.map(item => this.mapping(item)),
        m('.RepoSync-actions.RepoSync-save', [Button.component({ className: 'Button', disabled: this.busy || this.config.mappings.length >= 20, onclick: () => this.add() }, tr('add')),
          Button.component({ className: 'Button Button--primary', loading: this.busy, disabled: this.busy || !this.dirty,
            onclick: () => this.action('save', { ...this.config, secret: this.secret, token: this.token, clearToken: this.clearToken }) }, tr('save')),
          this.dirty && m('span.helpText', tr('unsaved'))]),
        m('section.RepoSync-card', [m('.RepoSync-cardHeader', [m('h3', tr('jobs')),
          m('.RepoSync-actions', [Button.component({ className: 'Button', disabled: this.busy || this.dirty, onclick: () => this.load() }, tr('refresh')),
            Button.component({ className: 'Button', disabled: this.busy || this.dirty || !this.config.enabled, onclick: () => this.action('run') }, tr('run'))])]),
          this.jobs.length ? m('ul.RepoSync-log', this.jobs.map(job => m('li', [m('strong', job.repo + ' · ' + job.kind),
            m('span', tr('status_' + job.status)), m('small', job.result ? tr('errors.' + job.result) : '—'), m('time', job.updated_at + ' UTC'),
            job.post_id && m('small', tr('post') + ': ' + job.post_id), job.status === 'error' && Button.component({ className: 'Button Button--small',
              disabled: this.busy || this.dirty, onclick: () => this.action('retry', { job: job.id }) }, tr('retry'))]))) : m('p.helpText', tr('no_jobs'))]),
        m('section.RepoSync-card', [m('h3', tr('backups')), m('p.helpText', tr('backup_help')),
          this.backups.length ? m('ul.RepoSync-backups', this.backups.map(backup => m('li', Button.component({ className: 'Button', disabled: this.busy,
            onclick: () => this.showBackup(backup.id) }, '#' + backup.post_id + ' · ' + backup.created_at + ' UTC')))) : m('p.helpText', tr('no_backups')),
          this.backup && m('.Form-group', [m('label', tr('backup_content') + ' #' + this.backup.post_id), m('textarea.FormControl', { readonly: true, rows: 12, value: this.backup.content })])]),
      ],
    ]);
  }
}

app.initializers.add('el-choco-repo-sync', () => {});
export const extend = [new Admin().page(RepoSyncPage)];
