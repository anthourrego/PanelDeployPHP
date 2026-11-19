import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Field, Hint, Icon, Modal, inputClassName, primaryButton, secondaryButton } from './ui';

function Actions({ onClose, processing, submitLabel }) {
    return (
        <div className="flex justify-end gap-3 pt-2">
            <button className={secondaryButton} onClick={onClose} type="button">Cancelar</button>
            <button className={primaryButton} disabled={processing} type="submit">{processing ? 'Guardando…' : submitLabel}</button>
        </div>
    );
}

function useModalForm(initial, url, onClose, resetFields) {
    const form = useForm(initial);

    function submit(event) {
        event.preventDefault();
        form.post(url, {
            onSuccess: () => {
                form.reset(...resetFields);
                onClose();
            },
        });
    }

    return { form, submit };
}

function Bind({ form, name, ...props }) {
    return <input className={inputClassName} onChange={(event) => form.setData(name, event.target.value)} value={form.data[name]} {...props} />;
}

export function ProjectModal({ onClose }) {
    const { form, submit } = useModalForm({
        name: '', gitea_url: '', owner: '', repository: '', ssh_url: '', gitea_token: '', preserved_paths: '',
    }, '/projects', onClose, []);

    return (
        <Modal onClose={onClose} title="Registrar proyecto">
            <form className="space-y-4" onSubmit={submit}>
                <Field error={form.errors.name} label="Nombre"><Bind form={form} name="name" required /></Field>
                <Field error={form.errors.gitea_url} label="URL base de Gitea"><Bind form={form} name="gitea_url" placeholder="http://192.168.0.214:3000" required type="url" /></Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field error={form.errors.owner} label="Propietario"><Bind form={form} name="owner" placeholder="Prosof_SAS" required /></Field>
                    <Field error={form.errors.repository} label="Repositorio"><Bind form={form} name="repository" placeholder="ProveedoresFive" required /></Field>
                </div>
                <Field error={form.errors.ssh_url} label="URL SSH del repositorio (opcional)">
                    <Bind form={form} name="ssh_url" placeholder="git@servidor:Propietario/Repositorio.git" />
                    <Hint>Con SSH, el panel consulta versiones usando su propia clave; Gitea no necesita HTTPS.</Hint>
                </Field>
                <Field error={form.errors.gitea_token} label="Token de Gitea (opcional, solo HTTPS)">
                    <Bind autoComplete="new-password" form={form} name="gitea_token" type="password" />
                </Field>
                <Field error={form.errors.preserved_paths} label="Rutas relativas a preservar, una por línea">
                    <textarea className={inputClassName} onChange={(event) => form.setData('preserved_paths', event.target.value)} placeholder={'application/config/database.php\nuploads'} rows={3} value={form.data.preserved_paths} />
                    <Hint>El agente bloqueará commits que cambien rutas protegidas o encuentre cambios locales fuera de ellas.</Hint>
                </Field>
                <Actions onClose={onClose} processing={form.processing} submitLabel="Registrar proyecto" />
            </form>
        </Modal>
    );
}

export function ServerModal({ onClose }) {
    const { form, submit } = useModalForm({ name: '' }, '/servers', onClose, []);

    return (
        <Modal onClose={onClose} title="Registrar servidor">
            <form className="space-y-4" onSubmit={submit}>
                <Field error={form.errors.name} label="Nombre"><Bind form={form} name="name" required /></Field>
                <Actions onClose={onClose} processing={form.processing} submitLabel="Registrar servidor" />
            </form>
        </Modal>
    );
}

export function AssignmentModal({ onClose, projects, servers, initialProjectId = '' }) {
    const { form, submit } = useModalForm({ project_id: String(initialProjectId), server_id: '', path: '' }, '/assignments', onClose, []);

    return (
        <Modal onClose={onClose} title="Asignar ruta">
            <form className="space-y-4" onSubmit={submit}>
                <Field error={form.errors.project_id} label="Proyecto">
                    <select className={inputClassName} onChange={(event) => form.setData('project_id', event.target.value)} required value={form.data.project_id}>
                        <option value="">Seleccione</option>
                        {projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                    </select>
                </Field>
                <Field error={form.errors.server_id} label="Servidor">
                    <select className={inputClassName} onChange={(event) => form.setData('server_id', event.target.value)} required value={form.data.server_id}>
                        <option value="">Seleccione</option>
                        {servers.map((server) => <option key={server.id} value={server.id}>{server.name}</option>)}
                    </select>
                </Field>
                <Field error={form.errors.path} label="Ruta absoluta en Windows"><Bind form={form} name="path" placeholder="C:\wamp64\www\proyecto" required /></Field>
                <Actions onClose={onClose} processing={form.processing} submitLabel="Guardar ruta" />
            </form>
        </Modal>
    );
}

export function DeploymentModal({ onClose, projects, servers }) {
    const { form, submit } = useModalForm({
        project_id: '', ref: '', scheduled_at: '', timezone: 'America/Bogota', servers: [],
    }, '/deployments', onClose, []);

    function toggleServer(serverId, checked) {
        const selected = form.data.servers;
        form.setData('servers', checked ? [...selected, String(serverId)] : selected.filter((id) => id !== String(serverId)));
    }

    return (
        <Modal onClose={onClose} title="Programar despliegue">
            <form className="space-y-4" onSubmit={submit}>
                <Field error={form.errors.project_id} label="Proyecto">
                    <select className={inputClassName} onChange={(event) => form.setData('project_id', event.target.value)} required value={form.data.project_id}>
                        <option value="">Seleccione</option>
                        {projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                    </select>
                </Field>
                <Field error={form.errors.ref} label="Referencia de Gitea (rama, etiqueta o commit)">
                    <Bind form={form} name="ref" required />
                    <Hint>Se consulta Gitea y se guarda el SHA exacto.</Hint>
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field error={form.errors.scheduled_at} label="Fecha y hora"><Bind form={form} name="scheduled_at" required type="datetime-local" /></Field>
                    <Field error={form.errors.timezone} label="Zona horaria">
                        <Bind form={form} name="timezone" required />
                        <Hint>Zona IANA, p. ej. America/Bogota.</Hint>
                    </Field>
                </div>
                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium text-slate-700 dark:text-slate-200">Servidores</legend>
                    {servers.length > 0 ? servers.map((server) => (
                        <label className="flex items-center gap-2 rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-2 text-sm text-slate-700 dark:text-slate-200" key={server.id}>
                            <input
                                checked={form.data.servers.includes(String(server.id))}
                                className="size-4 rounded border-slate-300 dark:border-slate-600 text-prosof-dark dark:text-prosof-lime focus:ring-prosof-lime"
                                onChange={(event) => toggleServer(server.id, event.target.checked)}
                                type="checkbox"
                            />
                            {server.name}
                        </label>
                    )) : <p className="text-sm text-slate-500 dark:text-slate-400">Registre un servidor primero.</p>}
                    {form.errors.servers && <p className="text-sm text-red-700 dark:text-red-300">{form.errors.servers}</p>}
                </fieldset>
                <Actions onClose={onClose} processing={form.processing} submitLabel="Guardar programación" />
            </form>
        </Modal>
    );
}

export function SshModal({ onClose, project }) {
    const form = useForm({ ssh_url: project.ssh_url ?? '' });

    function submit(event) {
        event.preventDefault();
        form.post(`/projects/${project.id}/ssh`, { onSuccess: onClose });
    }

    return (
        <Modal onClose={onClose} title={`Conexión SSH · ${project.name}`}>
            <form className="space-y-4" onSubmit={submit}>
                <Field error={form.errors.ssh_url} label="URL SSH para nuevas programaciones">
                    <Bind form={form} name="ssh_url" placeholder="git@servidor:Propietario/Repositorio.git" />
                </Field>
                <Actions onClose={onClose} processing={form.processing} submitLabel="Guardar conexión SSH" />
            </form>
        </Modal>
    );
}

export function TokenModal({ onClose, reveal }) {
    const [copied, setCopied] = useState(false);

    async function copy() {
        await navigator.clipboard.writeText(reveal.token);
        setCopied(true);
    }

    return (
        <Modal onClose={onClose} title={`Token del agente · ${reveal.server}`}>
            <div className="space-y-4">
                <p className="rounded-lg border-l-4 border-amber-500 bg-amber-50 dark:bg-amber-950/60 px-3 py-2 text-sm text-amber-950 dark:text-amber-100">
                    Este token se muestra <strong>una sola vez</strong>. Cópielo ahora y entréguelo por un canal seguro; si lo pierde deberá generar uno nuevo.
                </p>
                <code className="block break-all rounded-lg bg-slate-100 dark:bg-slate-800 px-3 py-3 text-sm text-slate-800 dark:text-slate-200 select-all">{reveal.token}</code>
                <div className="flex justify-end gap-3">
                    <button className={secondaryButton} onClick={copy} type="button"><Icon className="size-4" name="copy" />{copied ? 'Copiado' : 'Copiar'}</button>
                    <button className={primaryButton} onClick={onClose} type="button">Listo</button>
                </div>
            </div>
        </Modal>
    );
}
