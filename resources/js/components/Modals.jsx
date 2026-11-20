import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import DeploymentSchedule from './DeploymentSchedule';
import { Field, Hint, Icon, Modal, inputClassName, primaryButton, secondaryButton } from './ui';

const modalActionsClassName = 'sticky bottom-[-1.25rem] -mx-4 -mb-5 flex justify-end gap-3 bg-white px-4 pb-5 pt-4 sm:-mx-6 sm:px-6 dark:bg-slate-900';

function Actions({ disabled, onClose, processing, submitLabel }) {
    return (
        <div className={modalActionsClassName}>
            <button className={secondaryButton} onClick={onClose} type="button">Cancelar</button>
            <button className={primaryButton} disabled={disabled || processing} type="submit">{processing ? 'Guardando…' : submitLabel}</button>
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

export function PasswordModal({ onClose, updateUrl }) {
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });

    function submit(event) {
        event.preventDefault();
        form.patch(updateUrl, {
            preserveScroll: true,
            onSuccess: onClose,
            onFinish: () => form.reset(),
        });
    }

    return (
        <Modal onClose={onClose} title="Cambiar contraseña">
            <form className="space-y-4" onSubmit={submit}>
                <Field error={form.errors.current_password} label="Contraseña actual">
                    <Bind autoComplete="current-password" form={form} name="current_password" required type="password" />
                </Field>
                <Field error={form.errors.password} label="Nueva contraseña">
                    <Bind autoComplete="new-password" form={form} minLength={12} name="password" required type="password" />
                    <Hint>Mínimo 12 caracteres. Al guardar se cerrarán las demás sesiones.</Hint>
                </Field>
                <Field label="Confirmar nueva contraseña">
                    <Bind autoComplete="new-password" form={form} minLength={12} name="password_confirmation" required type="password" />
                </Field>
                <Actions onClose={onClose} processing={form.processing} submitLabel="Cambiar contraseña" />
            </form>
        </Modal>
    );
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
    const [references, setReferences] = useState(null);
    const [referenceError, setReferenceError] = useState('');
    const [reload, setReload] = useState(0);
    const referencesUrl = projects.find((project) => String(project.id) === form.data.project_id)?.references_url;

    useEffect(() => {
        if (!referencesUrl) return;

        const controller = new AbortController();
        async function loadReferences() {
            try {
                const response = await fetch(referencesUrl, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('No se pudo consultar el repositorio.');

                const result = await response.json();
                if (!controller.signal.aborted) setReferences(result);
            } catch {
                if (!controller.signal.aborted) {
                    setReferenceError('No se pudieron cargar las ramas y etiquetas. Verifique la conexión con el repositorio e intente de nuevo.');
                }
            }
        }
        loadReferences();

        return () => controller.abort();
    }, [referencesUrl, reload]);

    function changeProject(projectId) {
        setReferences(null);
        setReferenceError('');
        form.clearErrors('project_id', 'ref');
        form.setData({ ...form.data, project_id: projectId, ref: '' });
    }

    const hasReferences = references && (references.tags.length > 0 || references.branches.length > 0);
    const loadingReferences = Boolean(referencesUrl && !references && !referenceError);

    function toggleServer(serverId, checked) {
        const selected = form.data.servers;
        form.setData('servers', checked ? [...selected, String(serverId)] : selected.filter((id) => id !== String(serverId)));
    }

    return (
        <Modal onClose={onClose} title="Programar despliegue">
            <form className="space-y-4" onSubmit={submit}>
                <Field error={form.errors.project_id} label="Proyecto">
                    <select className={inputClassName} onChange={(event) => changeProject(event.target.value)} required value={form.data.project_id}>
                        <option value="">Seleccione</option>
                        {projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                    </select>
                </Field>
                <Field error={form.errors.ref} label="Rama o etiqueta">
                    <select aria-busy={loadingReferences} className={inputClassName} disabled={!hasReferences} onChange={(event) => form.setData('ref', event.target.value)} required value={form.data.ref}>
                        <option value="">{loadingReferences ? 'Consultando el repositorio…' : !referencesUrl ? 'Seleccione un proyecto primero' : 'Seleccione una rama o etiqueta'}</option>
                        {references?.tags.length > 0 && (
                            <optgroup label="Etiquetas (tags)">
                                {references.tags.map((name) => <option key={name} value={`refs/tags/${name}`}>{name}</option>)}
                            </optgroup>
                        )}
                        {references?.branches.length > 0 && (
                            <optgroup label="Ramas">
                                {references.branches.map((name) => <option key={name} value={`refs/heads/${name}`}>{name}</option>)}
                            </optgroup>
                        )}
                    </select>
                    <Hint>Se desplegará la versión que tenga la rama o etiqueta al guardar la programación.</Hint>
                </Field>
                {loadingReferences && (
                    <div className="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200" role="status">
                        <Icon className="size-5 shrink-0 text-prosof-dark motion-safe:animate-spin dark:text-prosof-lime" name="refresh" />
                        <span>Consultando ramas y etiquetas, espera un momento…</span>
                    </div>
                )}
                {references && !hasReferences && <p className="text-sm text-slate-600 dark:text-slate-300" role="status">No se encontraron ramas ni etiquetas.</p>}
                {referenceError && (
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="text-sm text-red-700 dark:text-red-300" role="alert">{referenceError}</p>
                        <button className={secondaryButton} onClick={() => { setReferenceError(''); setReload((current) => current + 1); }} type="button">Reintentar</button>
                    </div>
                )}
                <DeploymentSchedule
                    error={form.errors.scheduled_at}
                    onChange={(value) => form.setData('scheduled_at', value)}
                    timezone={form.data.timezone}
                    value={form.data.scheduled_at}
                />
                <Field error={form.errors.timezone} label="Zona horaria">
                    <Bind form={form} name="timezone" required />
                    <Hint>Zona IANA, p. ej. America/Bogota.</Hint>
                </Field>
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
                <Actions disabled={!form.data.ref || !form.data.scheduled_at} onClose={onClose} processing={form.processing} submitLabel="Guardar programación" />
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

export function WindowsAgentGuideModal({ onClose }) {
    return (
        <Modal onClose={onClose} title="Cómo instalar el agente de Windows">
            <div className="flex flex-col gap-5 text-sm text-slate-700 dark:text-slate-300">
                <ol className="flex list-decimal flex-col gap-3 pl-5 marker:font-bold">
                    <li>
                        <strong>Prepara Windows.</strong> Instala Git y, si usas repositorios SSH, OpenSSH. Verifica desde Windows que la URL HTTPS del panel abre sin alertas de certificado. La cuenta que ejecutará la tarea debe poder iniciar sesión como tarea por lotes y acceder al repositorio sin interacción.
                    </li>
                    <li>
                        <strong>Registra el servidor y genera su token.</strong> En <strong>Servidores</strong>, busca el servidor y pulsa <strong>Generar token</strong>. Cópialo cuando aparezca: se muestra una sola vez. No lo compartas por chat ni correo.
                    </li>
                    <li>
                        <strong>Instala el agente.</strong> Descarga el instalador y abre <code>PanelDeploySetup.exe</code> en el servidor Windows x64. Acepta la elevación e introduce la URL base HTTPS del panel (sin <code>/login</code>), la cuenta de Windows, su contraseña y el token del servidor.
                    </li>
                    <li>
                        <strong>Valida y habilita la tarea.</strong> El instalador deja <code>PanelDeployAgent</code> deshabilitada. Antes de habilitarla en el <strong>Programador de tareas</strong>, revisa las programaciones pendientes: los trabajos vencidos pueden empezar en la primera ejecución. Haz la primera prueba en un servidor y un repositorio no productivos.
                    </li>
                    <li>
                        <strong>Confirma la conexión.</strong> Una vez habilitada, la tarea se ejecuta cada cinco minutos. Comprueba en el panel que el servidor aparece en línea y revisa el resultado del despliegue de prueba.
                    </li>
                </ol>
                <details className="rounded-lg bg-slate-100 p-3 dark:bg-slate-800">
                    <summary className="cursor-pointer font-semibold">Instalación alternativa con PowerShell (TI)</summary>
                    <div className="mt-2 flex flex-col gap-2">
                        <ol className="flex list-decimal flex-col gap-2 pl-5">
                            <li>Descarga y extrae el ZIP. Mantén <code>Install-PanelDeployAgent.ps1</code> y <code>PanelDeployAgent.ps1</code> en la misma carpeta.</li>
                            <li>Abre Windows PowerShell como administrador en esa carpeta. Usa los scripts según la política de ejecución de tu organización y ejecuta el comando de abajo con la URL HTTPS real del panel.</li>
                            <li>Introduce la cuenta Windows, su contraseña y el token del servidor cuando el instalador los solicite. La tarea queda deshabilitada; sigue los pasos 4 y 5 para validarla y habilitarla.</li>
                        </ol>
                        <code className="break-all">.\Install-PanelDeployAgent.ps1 -PanelUrl 'https://panel.ejemplo.local'</code>
                    </div>
                </details>
                <details className="rounded-lg bg-slate-100 p-3 dark:bg-slate-800">
                    <summary className="cursor-pointer font-semibold">Si ya tienes un agente instalado</summary>
                    <p className="mt-2">
                        Deshabilita la tarea y espera a que termine cualquier ejecución activa. Usa <strong>Descargar solo el agente</strong> y copia el ejecutable en la carpeta protegida, sin borrar <code>config.json</code> ni la carpeta <code>estado</code>. Si migras desde PowerShell, en una instalación con la ruta predeterminada usa <code className="break-all">C:\ProgramData\PanelDeploy\PanelDeployAgent.exe</code> como programa y <code className="break-all">--config "C:\ProgramData\PanelDeploy\config.json"</code> como argumentos. Revisa los resultados pendientes antes de habilitarla de nuevo.
                    </p>
                </details>
                <div className={modalActionsClassName}>
                    <button className={primaryButton} onClick={onClose} type="button">Listo</button>
                </div>
            </div>
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
                <div className={modalActionsClassName}>
                    <button className={secondaryButton} onClick={copy} type="button"><Icon className="size-4" name="copy" />{copied ? 'Copiado' : 'Copiar'}</button>
                    <button className={primaryButton} onClick={onClose} type="button">Listo</button>
                </div>
            </div>
        </Modal>
    );
}
