import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { AssignmentModal, DeploymentModal, PasswordModal, ProjectModal, ServerModal, SshModal, TokenModal, WindowsAgentGuideModal } from '../components/Modals';
import ThemeToggle from '../components/ThemeToggle';
import { confirmAction, notifyError, notifySuccess } from '../lib/notify';
import { Badge, Card, EmptyState, Field, Hint, Icon, Modal, PasswordInput, StatusBadge, dangerButton, inputClassName, primaryButton, secondaryButton, targetStatuses } from '../components/ui';

const SIDEBAR_KEY = 'panel.sidebar.collapsed';
const ONLINE_WINDOW_MS = 5 * 60 * 1000;

const sections = [
    { key: 'summary', label: 'Resumen', icon: 'home' },
    { key: 'deployments', label: 'Programaciones', icon: 'rocket' },
    { key: 'projects', label: 'Proyectos', icon: 'folder' },
    { key: 'servers', label: 'Servidores', icon: 'server' },
    { key: 'downloads', label: 'Descargas', icon: 'download' },
    { key: 'users', label: 'Usuarios', icon: 'users' },
];

function runAction(method, url, { data, ...confirmation }) {
    confirmAction(confirmation).then((confirmed) => {
        if (confirmed) {
            router[method](url, data ?? {}, { preserveScroll: true });
        }
    });
}

function generateToken(method, url) {
    router[method](url, {}, { preserveScroll: true });
}

function isOnline(server) {
    return server.last_seen_at !== null && Date.now() - Date.parse(`${server.last_seen_at.replace(' ', 'T')}Z`) < ONLINE_WINDOW_MS;
}

function ServerStatus({ server }) {
    if (!server.is_active) {
        return <Badge tone="danger">Desactivado</Badge>;
    }

    if (server.active_target_id) {
        return <Badge tone="info">Trabajando</Badge>;
    }

    return isOnline(server) ? <Badge tone="success">En línea</Badge> : <Badge>Sin conexión</Badge>;
}

function StatCard({ icon, label, tone, value }) {
    return (
        <div className="flex items-center gap-4 rounded-2xl bg-white/90 p-5 shadow-sm ring-1 ring-green-900/10 dark:bg-[#17251a] dark:ring-white/15">
            <span className={`grid size-12 place-items-center rounded-xl ${tone}`}><Icon className="size-6" name={icon} /></span>
            <div>
                <p className="font-display text-2xl font-extrabold text-neutral-800 dark:text-slate-100">{value}</p>
                <p className="text-sm text-slate-500 dark:text-slate-400">{label}</p>
            </div>
        </div>
    );
}

function UserMenu({ onChangePassword, onEditProfile, onLogout, processing, user }) {
    const [open, setOpen] = useState(false);
    const container = useRef(null);
    const trigger = useRef(null);
    const initial = Array.from(user.name.trim() || user.email)[0].toLocaleUpperCase();

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const onPointerDown = (event) => {
            if (!container.current?.contains(event.target)) {
                setOpen(false);
            }
        };
        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                setOpen(false);
                trigger.current?.focus();
            }
        };

        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    return (
        <div className="relative" onBlur={(event) => {
            if (!event.currentTarget.contains(event.relatedTarget)) {
                setOpen(false);
            }
        }} ref={container}>
            <button
                aria-controls="user-menu"
                aria-expanded={open}
                aria-label="Menú de usuario"
                className="grid size-10 shrink-0 place-items-center rounded-full bg-prosof-action text-sm font-bold text-white transition hover:bg-prosof-action-hover"
                onClick={() => setOpen(!open)}
                ref={trigger}
                type="button"
            >
                {initial}
            </button>
            {open && (
                <div id="user-menu" className="absolute right-0 top-full z-50 mt-2 w-64 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl dark:border-white/15 dark:bg-[#17251a]">
                    <div className="border-b border-slate-200 px-4 py-3 dark:border-white/10">
                        <p className="break-words text-sm font-bold text-slate-900 dark:text-slate-100">{user.name}</p>
                        <p className="mt-1 break-all text-xs text-slate-600 dark:text-slate-300">{user.email}</p>
                    </div>
                    <button
                        aria-haspopup="dialog"
                        className="flex w-full items-center gap-3 px-4 py-3 text-left text-sm font-semibold text-slate-700 transition hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-white/10"
                        onClick={() => {
                            setOpen(false);
                            onEditProfile();
                        }}
                        type="button"
                    >
                        <Icon className="size-4" name="user" />Editar perfil
                    </button>
                    <button
                        aria-haspopup="dialog"
                        className="flex w-full items-center gap-3 px-4 py-3 text-left text-sm font-semibold text-slate-700 transition hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-white/10"
                        onClick={() => {
                            setOpen(false);
                            onChangePassword();
                        }}
                        type="button"
                    >
                        <Icon className="size-4" name="key" />Cambiar contraseña
                    </button>
                    <button
                        className="flex w-full items-center gap-3 px-4 py-3 text-left text-sm font-semibold text-red-800 transition hover:bg-red-50 disabled:opacity-60 dark:text-red-300 dark:hover:bg-red-950/60"
                        disabled={processing}
                        onClick={() => {
                            setOpen(false);
                            onLogout();
                        }}
                        type="button"
                    >
                        <Icon className="size-4" name="logout" />Cerrar sesión
                    </button>
                </div>
            )}
        </div>
    );
}

function CancelTargetButton({ target }) {
    const form = useForm({});

    return (
        <button
            className="rounded-md border border-red-600 dark:border-red-800 bg-red-50 dark:bg-transparent px-2.5 py-1 text-xs font-semibold text-red-800 dark:text-red-200 transition hover:bg-red-100 dark:hover:bg-red-950/70 disabled:opacity-60"
            disabled={form.processing}
            onClick={async () => {
                const confirmed = await confirmAction({
                    title: '¿Cancelar este trabajo?',
                    text: 'El trabajo pendiente no se ejecutará en el servidor.',
                    confirmText: 'Sí, cancelar',
                    danger: true,
                });
                if (confirmed) {
                    form.post(`/targets/${target.id}/cancel`);
                }
            }}
            type="button"
        >
            Cancelar
        </button>
    );
}

function TargetsList({ targets }) {
    return targets.map((target) => (
        <div className="flex flex-wrap items-center gap-2" key={target.id}>
            <span className="font-medium text-slate-700 dark:text-slate-200">{target.server_name}</span>
            <StatusBadge status={target.status} />
            {target.status === 'pending' && <CancelTargetButton target={target} />}
            {target.result && <span className="basis-full text-xs text-slate-500 dark:text-slate-400">{target.result}</span>}
        </div>
    ));
}

function DeploymentActions({ deployment }) {
    const hasPending = deployment.targets.some((target) => target.status === 'pending');

    return (
        <div className="mt-2 flex flex-wrap gap-2">
            {hasPending && (
                <button
                    className={dangerButton}
                    onClick={() => runAction('post', `/deployments/${deployment.id}/cancel`, {
                        title: '¿Cancelar la programación?',
                        text: 'Se cancelarán todos los trabajos pendientes de esta programación.',
                        confirmText: 'Sí, cancelar',
                        danger: true,
                    })}
                    type="button"
                >
                    Cancelar programación
                </button>
            )}
            {deployment.can_delete && (
                <button
                    className={dangerButton}
                    onClick={() => runAction('delete', `/deployments/${deployment.id}`, {
                        title: '¿Eliminar la programación?',
                        text: 'Se borrará del listado. Esta acción no se puede deshacer.',
                        confirmText: 'Sí, eliminar',
                        danger: true,
                    })}
                    type="button"
                >
                    <Icon className="size-3.5" name="trash" />Eliminar
                </button>
            )}
        </div>
    );
}

function DeploymentHistory({ filters, history, projects, servers, url }) {
    const form = useForm({
        project_id: filters.project_id ?? '',
        server_id: filters.server_id ?? '',
        status: filters.status ?? '',
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
    });
    const pageUrl = (page) => {
        const params = new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== null && value !== ''));
        params.set('page', page);

        return `${url}?${params.toString()}`;
    };

    return (
        <div className="space-y-5">
            <Card title="Filtrar historial">
                <form className="space-y-4 p-5" onSubmit={(event) => {
                    event.preventDefault();
                    form.get(url, { preserveScroll: true });
                }}>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Field error={form.errors.project_id} label="Proyecto">
                            <select className={inputClassName} onChange={(event) => form.setData('project_id', event.target.value)} value={form.data.project_id}>
                                <option value="">Todos los proyectos</option>
                                {projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                            </select>
                        </Field>
                        <Field error={form.errors.server_id} label="Servidor">
                            <select className={inputClassName} onChange={(event) => form.setData('server_id', event.target.value)} value={form.data.server_id}>
                                <option value="">Todos los servidores</option>
                                {servers.map((server) => <option key={server.id} value={server.id}>{server.name}</option>)}
                            </select>
                        </Field>
                        <Field error={form.errors.status} label="Estado del servidor">
                            <select className={inputClassName} onChange={(event) => form.setData('status', event.target.value)} value={form.data.status}>
                                <option value="">Todos los estados</option>
                                {Object.entries(targetStatuses).map(([status, { label }]) => <option key={status} value={status}>{label}</option>)}
                            </select>
                        </Field>
                        <Field error={form.errors.date_from} label="Fecha programada desde (UTC)">
                            <input className={inputClassName} max="9999-12-30" min="1753-01-01" onChange={(event) => form.setData('date_from', event.target.value)} type="date" value={form.data.date_from} />
                        </Field>
                        <Field error={form.errors.date_to} label="Fecha programada hasta (UTC)">
                            <input className={inputClassName} max="9999-12-30" min={form.data.date_from || '1753-01-01'} onChange={(event) => form.setData('date_to', event.target.value)} type="date" value={form.data.date_to} />
                        </Field>
                    </div>
                    <p className="text-xs text-slate-500 dark:text-slate-400">Las fechas incluyen el día completo en UTC. El servidor y el estado deben coincidir en el mismo trabajo.</p>
                    <div className="flex flex-wrap gap-2">
                        <button className={primaryButton} disabled={form.processing} type="submit">Aplicar filtros</button>
                        <Link className={secondaryButton} href={url}>Limpiar filtros</Link>
                    </div>
                </form>
            </Card>
            <Card title={`Programaciones (${history.total})`}>
                <DeploymentsTable deployments={history.data} emptyMessage={history.current_page > history.last_page ? 'No hay programaciones en esta página.' : Object.values(filters).some(Boolean) ? 'No se encontraron programaciones para los filtros seleccionados.' : 'Aún no hay programaciones.'} />
                {history.last_page > 1 || history.current_page > 1 ? (
                    <nav aria-label="Paginación del historial" className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4 text-sm dark:border-slate-800">
                        <span className="text-slate-600 dark:text-slate-300">Página {history.current_page} de {history.last_page}</span>
                        <div className="flex gap-2">
                            {history.current_page > 1 && <Link className={secondaryButton} href={pageUrl(history.current_page > history.last_page ? 1 : history.current_page - 1)} preserveScroll>{history.current_page > history.last_page ? 'Primera página' : 'Anterior'}</Link>}
                            {history.current_page < history.last_page && <Link className={secondaryButton} href={pageUrl(history.current_page + 1)} preserveScroll>Siguiente</Link>}
                        </div>
                    </nav>
                ) : null}
            </Card>
        </div>
    );
}

function DeploymentsTable({ deployments, emptyMessage = 'Aún no hay programaciones.', limit }) {
    const rows = limit ? deployments.slice(0, limit) : deployments;

    if (rows.length === 0) {
        return <EmptyState>{emptyMessage}</EmptyState>;
    }

    return (
        <>
            <ul className="divide-y divide-slate-100 dark:divide-slate-800 md:hidden">
                {rows.map((deployment) => (
                    <li className="space-y-3 px-4 py-4" key={deployment.id}>
                        <div>
                            <p className="font-semibold text-neutral-800 dark:text-slate-100">{deployment.project_name}</p>
                            <p className="mt-0.5 text-sm text-slate-600 dark:text-slate-300">{deployment.ref} · <code className="text-xs" title={deployment.commit}>{deployment.commit.slice(0, 8)}</code></p>
                            <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{deployment.scheduled_at} UTC</p>
                            <DeploymentActions deployment={deployment} />
                        </div>
                        <div className="space-y-2 text-sm"><TargetsList targets={deployment.targets} /></div>
                    </li>
                ))}
            </ul>
            <div className="hidden overflow-x-auto md:block">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50 dark:bg-slate-950 text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <tr>
                            <th className="px-5 py-3 font-semibold">Proyecto / versión</th>
                            <th className="px-5 py-3 font-semibold">Fecha (UTC)</th>
                            <th className="px-5 py-3 font-semibold">Servidores</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                        {rows.map((deployment) => (
                            <tr className="align-top hover:bg-slate-50/60 dark:hover:bg-slate-800/60" key={deployment.id}>
                                <td className="px-5 py-4">
                                    <p className="font-semibold text-neutral-800 dark:text-slate-100">{deployment.project_name}</p>
                                    <p className="mt-0.5 text-slate-600 dark:text-slate-300">{deployment.ref} · <code className="text-xs" title={deployment.commit}>{deployment.commit.slice(0, 8)}</code></p>
                                    <DeploymentActions deployment={deployment} />
                                </td>
                                <td className="whitespace-nowrap px-5 py-4 text-slate-600 dark:text-slate-300">{deployment.scheduled_at}</td>
                                <td className="space-y-2 px-5 py-4"><TargetsList targets={deployment.targets} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    );
}

function ServerActions({ server }) {
    const canDelete = !server.has_history && !server.active_target_id;

    return (
        <>
            <button
                className={secondaryButton.replace('px-4 py-2 text-sm', 'px-3 py-1.5 text-xs')}
                disabled={server.active_target_id !== null}
                onClick={() => (server.has_token ? runAction : generateToken)('post', `/servers/${server.id}/token`, {
                    title: '¿Regenerar el token?',
                    text: 'El token anterior dejará de funcionar y deberá actualizarlo en el agente de este servidor.',
                    confirmText: 'Sí, regenerar',
                    danger: true,
                })}
                type="button"
            >
                <Icon className="size-3.5" name="key" />{server.has_token ? 'Regenerar token' : 'Generar token'}
            </button>
            <button
                className={secondaryButton.replace('px-4 py-2 text-sm', 'px-3 py-1.5 text-xs')}
                disabled={server.is_active && server.active_target_id !== null}
                onClick={() => runAction('post', `/servers/${server.id}/active`, {
                    data: { is_active: !server.is_active },
                    title: server.is_active ? '¿Desactivar el servidor?' : '¿Activar el servidor?',
                    text: server.is_active ? 'No recibirá nuevos trabajos; los pendientes quedarán en espera.' : 'Volverá a recibir trabajos programados.',
                    confirmText: server.is_active ? 'Sí, desactivar' : 'Sí, activar',
                    danger: server.is_active,
                })}
                title={server.is_active && server.active_target_id !== null ? 'Tiene un trabajo en ejecución' : undefined}
                type="button"
            >
                <Icon className="size-3.5" name="power" />{server.is_active ? 'Desactivar' : 'Activar'}
            </button>
            {canDelete && (
                <button
                    className={dangerButton}
                    onClick={() => runAction('delete', `/servers/${server.id}`, {
                        title: '¿Eliminar el servidor?',
                        text: `Se eliminará "${server.name}" y sus rutas asignadas. Esta acción no se puede deshacer.`,
                        confirmText: 'Sí, eliminar',
                        danger: true,
                    })}
                    type="button"
                >
                    <Icon className="size-3.5" name="trash" />Eliminar
                </button>
            )}
        </>
    );
}

function ServersList({ servers, showActions = false }) {
    if (servers.length === 0) {
        return <EmptyState>No hay servidores registrados.</EmptyState>;
    }

    return (
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {servers.map((server) => (
                <li className="flex flex-wrap items-center justify-between gap-3 px-5 py-4" key={server.id}>
                    <div className="flex min-w-0 items-center gap-3">
                        <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300"><Icon name="server" /></span>
                        <div className="min-w-0">
                            <p className="font-semibold text-neutral-800 dark:text-slate-100">{server.name}</p>
                            <p className="text-xs text-slate-500 dark:text-slate-400">ID {server.id} · última conexión: {server.last_seen_at ? `${server.last_seen_at} UTC` : 'nunca'}{server.active_target_id && ` · trabajo activo ${server.active_target_id}`}</p>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <ServerStatus server={server} />
                        {showActions && <ServerActions server={server} />}
                    </div>
                </li>
            ))}
        </ul>
    );
}

function ProjectsGrid({ onAssign, onScheduleUpdate, onSsh, projects, syncAllUrl }) {
    const [syncing, setSyncing] = useState(null);
    const [selectedProjectId, setSelectedProjectId] = useState(projects[0]?.id ?? null);

    function syncTags(projectId = null) {
        setSyncing(projectId ?? 'all');
        router.post(projectId === null ? syncAllUrl : `/projects/${projectId}/tags/sync`, {}, {
            preserveScroll: true,
            onFinish: () => setSyncing(null),
        });
    }

    if (projects.length === 0) {
        return <div className="rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-700"><EmptyState>No hay proyectos registrados.</EmptyState></div>;
    }

    const project = projects.find((listedProject) => listedProject.id === selectedProjectId) ?? projects[0];
    const outdatedServers = project.servers.filter((server) => server.is_active && server.version_status === 'behind');

    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">Consulta servidores y versiones disponibles.</p>
                    <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">La consulta de versiones no inicia despliegues.</p>
                </div>
                <button className={secondaryButton} disabled={syncing !== null} onClick={() => syncTags()} type="button">
                    {syncing === 'all' ? 'Sincronizando todos…' : 'Sincronizar todos'}
                </button>
            </div>
            <div className="grid items-start gap-5 lg:grid-cols-[minmax(16rem,0.85fr)_minmax(0,1.8fr)]">
                <aside className="overflow-hidden rounded-2xl bg-white/90 shadow-sm ring-1 ring-green-900/10 dark:bg-[#17251a] dark:ring-white/15" aria-label="Lista de proyectos">
                    <div className="flex items-center justify-between gap-3 border-b border-green-900/10 px-4 py-4 dark:border-white/10">
                        <div>
                            <h2 className="font-display text-base font-bold text-neutral-800 dark:text-slate-100">Listado</h2>
                            <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Selecciona para ver detalles</p>
                        </div>
                        <Badge>{projects.length}</Badge>
                    </div>
                    <ul className="grid max-h-[min(32rem,65vh)] gap-1.5 overflow-y-auto p-2 sm:grid-cols-2 lg:grid-cols-1">
                        {projects.map((listedProject) => {
                            const pendingUpdates = listedProject.servers.filter((server) => server.is_active && server.version_status === 'behind').length;
                            const isSelected = listedProject.id === project.id;

                            return (
                                <li key={listedProject.id}>
                                    <button
                                        aria-controls="project-details"
                                        aria-pressed={isSelected}
                                        className={`w-full rounded-xl border p-3 text-left transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prosof-action ${
                                            isSelected
                                                ? 'border-prosof-dark/40 bg-prosof-lime/15 ring-1 ring-prosof-dark/20 dark:border-prosof-lime/40 dark:bg-prosof-lime/10 dark:ring-prosof-lime/20'
                                                : 'border-transparent hover:border-slate-200 hover:bg-slate-50 dark:hover:border-slate-700 dark:hover:bg-slate-900'
                                        }`}
                                        onClick={() => setSelectedProjectId(listedProject.id)}
                                        type="button"
                                    >
                                        <span className="flex items-start justify-between gap-2">
                                            <span className="min-w-0">
                                                <span className="block break-words text-sm font-semibold text-slate-800 dark:text-slate-100">{listedProject.name}</span>
                                                <span className="mt-0.5 block break-all font-mono text-[11px] text-slate-500 dark:text-slate-400">{listedProject.owner}/{listedProject.repository}</span>
                                            </span>
                                            {pendingUpdates > 0 && <Badge tone="warning">{pendingUpdates}</Badge>}
                                        </span>
                                        <span className="mt-2 flex items-center justify-between gap-2 text-xs text-slate-500 dark:text-slate-400">
                                            <span className="inline-flex items-center gap-1.5">
                                                <Icon className="size-3.5" name="server" />
                                                {listedProject.servers.length} {listedProject.servers.length === 1 ? 'servidor' : 'servidores'}
                                            </span>
                                            <span className="max-w-[9rem] truncate font-medium text-slate-700 dark:text-slate-300">{listedProject.latest_tag ?? 'Sin versión'}</span>
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </aside>
                <article
                    aria-labelledby="project-detail-heading"
                    className="flex flex-col overflow-hidden rounded-2xl border-t-4 border-t-prosof-lime bg-white/95 p-5 shadow-sm ring-1 ring-green-900/10 transition-shadow hover:shadow-md dark:bg-[#17251a] dark:ring-white/15"
                    id="project-details"
                >
                    <div className="flex items-start justify-between gap-3">
                        <div className="flex min-w-0 items-center gap-3">
                            <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-prosof-lime/20 text-prosof-dark dark:bg-prosof-lime/10 dark:text-prosof-lime">
                                <Icon className="size-5" name="folder" />
                            </span>
                            <div className="min-w-0">
                                <h2 className="break-words font-display text-lg font-bold text-neutral-800 dark:text-slate-100" id="project-detail-heading">{project.name}</h2>
                                <p className="mt-0.5 break-all font-mono text-xs text-slate-500 dark:text-slate-400">{project.owner}/{project.repository}</p>
                            </div>
                        </div>
                        <div className="flex shrink-0 flex-wrap justify-end gap-1.5">
                            {!project.is_active && <Badge tone="danger">Desactivado</Badge>}
                            <Badge tone={project.ssh_url ? 'success' : 'neutral'}>{project.ssh_url ? 'SSH' : 'Sin SSH'}</Badge>
                        </div>
                    </div>
                    <div className="mt-4 flex-1">
                        {project.servers.length > 0 ? (
                            <ul className="space-y-2">
                                {project.servers.map((server) => (
                                    <li className="flex items-start gap-3 rounded-xl border border-slate-200/80 bg-slate-50/70 px-3 py-3 text-sm transition-colors hover:border-prosof-dark/25 hover:bg-white dark:border-slate-700 dark:bg-slate-950/60 dark:hover:border-prosof-lime/30 dark:hover:bg-slate-900" key={server.id}>
                                        <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg bg-white text-slate-500 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700">
                                            <Icon className="size-4" name="server" />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                                <span className="font-semibold text-slate-700 dark:text-slate-200">{server.name}</span>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    {!server.is_active && <Badge tone="danger">Desactivado</Badge>}
                                                    {server.version_status === 'behind' && <Badge tone="warning">Versión inferior</Badge>}
                                                    {server.version_status === 'current' && <Badge tone="success">Actualizado</Badge>}
                                                    {server.version_status === 'ahead' && <Badge tone="info">Versión posterior</Badge>}
                                                    {server.version_status === 'unknown' && project.latest_tag && <Badge>No comparable</Badge>}
                                                </div>
                                            </div>
                                            <code className="mt-1 inline-flex max-w-full break-all rounded bg-white/80 px-1.5 py-0.5 font-mono text-[11px] text-slate-500 dark:bg-slate-900 dark:text-slate-400">{server.path}</code>
                                            {server.last_successful_deployment && (
                                                <p className="mt-2 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-slate-600 dark:text-slate-300">
                                                    <span>Última confirmada</span>
                                                    <span className="font-semibold">{server.last_successful_deployment.ref.replace(/^refs\/(?:tags|heads)\//, '')}</span>
                                                    <code className="rounded bg-white/80 px-1 py-0.5 dark:bg-slate-900" title={server.last_successful_deployment.commit}>{server.last_successful_deployment.commit.slice(0, 8)}</code>
                                                    <span>{server.last_successful_deployment.finished_at} UTC</span>
                                                </p>
                                            )}
                                            {server.version_status === 'unknown' && server.version_status_reason && (
                                                <p className="mt-2 break-words border-l-2 border-slate-300 pl-2 text-xs text-slate-600 dark:border-slate-600 dark:text-slate-300">{server.version_status_reason}</p>
                                            )}
                                            {server.deployment_uncertain && <p className="mt-2 border-l-2 border-amber-400 pl-2 text-xs text-amber-700 dark:text-amber-300">Un despliegue posterior dejó la versión actual sin verificar.</p>}
                                        </div>
                                        <button
                                            aria-label={`Quitar ruta de ${server.name}`}
                                            className="shrink-0 rounded-lg p-2 text-slate-400 transition hover:bg-red-50 hover:text-red-700 dark:text-slate-400 dark:hover:bg-red-950/70 dark:hover:text-red-300"
                                            onClick={() => runAction('delete', `/projects/${project.id}/servers/${server.id}`, {
                                                title: '¿Quitar esta ruta?',
                                                text: `El proyecto dejará de poder desplegarse en ${server.name}.`,
                                                confirmText: 'Sí, quitar',
                                                danger: true,
                                            })}
                                            type="button"
                                        >
                                            <Icon className="size-4" name="trash" />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        ) : <p className="text-sm text-slate-500 dark:text-slate-400">Sin servidores asignados.</p>}
                    </div>
                    <div className="mt-4 rounded-xl border border-slate-200/80 bg-gradient-to-br from-slate-50 to-white p-3.5 dark:border-slate-700 dark:from-slate-900 dark:to-slate-950">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Última versión disponible</p>
                                {project.latest_tag ? (
                                    <p className="mt-1.5 break-all font-display text-base font-bold text-slate-800 dark:text-slate-100">
                                        {project.latest_tag}{project.latest_tag_commit && <> <code className="ml-1 rounded bg-white/80 px-1.5 py-0.5 font-mono text-[11px] font-medium text-slate-500 dark:bg-slate-800 dark:text-slate-300">{project.latest_tag_commit.slice(0, 8)}</code></>}
                                    </p>
                                ) : (
                                    <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">
                                        {project.latest_tag_synced_at ? 'No se encontraron tags SemVer.' : 'Aún no sincronizada.'}
                                    </p>
                                )}
                                <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    Última consulta: {project.latest_tag_synced_at ? `${project.latest_tag_synced_at} UTC` : 'nunca'}
                                </p>
                            </div>
                            <button
                                aria-busy={syncing === project.id}
                                aria-label={`Sincronizar tags de ${project.name}`}
                                className="grid size-10 shrink-0 place-items-center rounded-lg border border-slate-300 bg-white text-slate-700 shadow-sm transition enabled:cursor-pointer enabled:hover:border-slate-400 enabled:hover:bg-slate-100 enabled:active:bg-slate-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prosof-action disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:enabled:hover:border-slate-500 dark:enabled:hover:bg-slate-700 dark:enabled:active:bg-slate-600"
                                disabled={syncing !== null}
                                onClick={() => syncTags(project.id)}
                                title={syncing === project.id ? 'Sincronizando…' : 'Sincronizar tags'}
                                type="button"
                            >
                                <Icon className={`size-4 ${syncing === project.id ? 'motion-safe:animate-spin' : ''}`} name="refresh" />
                            </button>
                        </div>
                        {project.latest_tag_sync_error && <p className="mt-2 text-xs text-red-700 dark:text-red-300">{project.latest_tag_sync_error}</p>}
                    </div>
                    {project.is_active && outdatedServers.length > 0 && (
                        <div className="mt-4 flex flex-col gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/50 sm:flex-row sm:items-center sm:justify-between" role="status">
                            <div className="flex items-start gap-3">
                                <Icon className="mt-0.5 size-5 shrink-0 text-amber-700 dark:text-amber-300" name="alert" />
                                <div className="min-w-0">
                                    <p className="font-semibold text-amber-950 dark:text-amber-100">Actualización recomendada</p>
                                    <p className="mt-1 text-sm text-amber-900 dark:text-amber-200">
                                        {outdatedServers.length} {outdatedServers.length === 1 ? 'servidor tiene' : 'servidores tienen'} una versión anterior a {project.latest_tag}: {outdatedServers.map((server) => server.name).join(', ')}.
                                    </p>
                                </div>
                            </div>
                            <button
                                aria-label={`Programar actualización de ${project.name} a ${project.latest_tag}`}
                                className={`${primaryButton} shrink-0`}
                                onClick={() => onScheduleUpdate(project.id, project.latest_tag, outdatedServers.map((server) => server.id))}
                                type="button"
                            >
                                <Icon className="size-4" name="rocket" />Programar actualización
                            </button>
                        </div>
                    )}
                    {project.latest_deployment && (
                        <div className="mt-4 rounded-lg bg-slate-50 p-3 dark:bg-slate-950">
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Último lanzamiento</p>
                            <p className="mt-1 break-all text-sm font-semibold text-slate-700 dark:text-slate-200">
                                {project.latest_deployment.ref} · <code className="text-xs">{project.latest_deployment.commit.slice(0, 8)}</code>
                            </p>
                            <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                Creado: {project.latest_deployment.created_at} UTC · Programado: {project.latest_deployment.scheduled_at} UTC
                            </p>
                            <ul className="mt-2 space-y-1">
                                {project.latest_deployment.targets.map((target) => (
                                    <li className="flex items-center justify-between gap-2 text-xs" key={target.server_name}>
                                        <span className="truncate text-slate-600 dark:text-slate-300">{target.server_name}</span>
                                        <StatusBadge status={target.status} />
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    <div className="mt-4 flex flex-wrap gap-2 border-t border-slate-100 dark:border-slate-800 pt-4">
                        <button className={secondaryButton} onClick={() => onAssign(project.id)} type="button"><Icon className="size-4" name="plus" />Asignar ruta</button>
                        <button className={secondaryButton} onClick={() => onSsh(project)} type="button"><Icon className="size-4" name="link" />Conexión SSH</button>
                        <button
                            className={secondaryButton}
                            onClick={() => runAction('post', `/projects/${project.id}/active`, {
                                data: { is_active: !project.is_active },
                                title: project.is_active ? '¿Desactivar el proyecto?' : '¿Activar el proyecto?',
                                text: project.is_active ? 'No se podrán programar nuevos despliegues hasta activarlo de nuevo.' : 'Podrá programar despliegues de nuevo.',
                                confirmText: project.is_active ? 'Sí, desactivar' : 'Sí, activar',
                                danger: project.is_active,
                            })}
                            type="button"
                        >
                            <Icon className="size-4" name="power" />{project.is_active ? 'Desactivar' : 'Activar'}
                        </button>
                        {!project.has_deployments && (
                            <button
                                className={`${dangerButton} py-2 text-sm`}
                                onClick={() => runAction('delete', `/projects/${project.id}`, {
                                    title: '¿Eliminar el proyecto?',
                                    text: `Se eliminará "${project.name}" y sus rutas. Esta acción no se puede deshacer.`,
                                    confirmText: 'Sí, eliminar',
                                    danger: true,
                                })}
                                type="button"
                            >
                                <Icon className="size-4" name="trash" />Eliminar
                            </button>
                        )}
                    </div>
                </article>
            </div>
        </div>
    );
}

function WindowsAgentDownloads({ downloads, onOpenGuide }) {
    return (
        <Card title="Agente para Windows x64">
            <div className="flex flex-col gap-3 px-5 py-4">
                <p className="text-sm text-slate-600 dark:text-slate-300">
                    Usa el instalador para una instalación nueva o descarga solo el agente para actualizar una existente.
                    {(downloads?.installer || downloads?.agent) && downloads.version && <> Versión: {downloads.version}.</>}
                </p>
                <div className="flex flex-wrap items-center gap-3">
                    {downloads?.installer
                        ? <a className={primaryButton} download href={downloads.installer}>Descargar instalador (.exe)</a>
                        : <span className="text-sm text-slate-600 dark:text-slate-300">Instalador no publicado.</span>}
                    {downloads?.agent
                        ? <a className={secondaryButton} download href={downloads.agent}>Descargar solo el agente (.exe)</a>
                        : <span className="text-sm text-slate-600 dark:text-slate-300">Agente no publicado.</span>}
                    <button aria-haspopup="dialog" className={secondaryButton} onClick={onOpenGuide} type="button">Guía de instalación</button>
                </div>
                <p className="text-sm text-slate-600 dark:text-slate-300">
                    {downloads?.powershell
                        ? <a className="font-semibold underline" download href={downloads.powershell}>Descargar alternativa PowerShell (.zip)</a>
                        : 'Alternativa PowerShell no publicada.'}
                </p>
            </div>
        </Card>
    );
}

function UserForm({ editingUser, onClose, profile = false }) {
    const form = useForm({
        name: editingUser?.name ?? '',
        username: editingUser?.username ?? '',
        email: editingUser?.email ?? '',
        password: '',
        password_confirmation: '',
        is_admin: editingUser?.is_admin ?? false,
    });

    function submit(event) {
        event.preventDefault();
        if (editingUser) {
            form.transform(({ name, username, email, is_admin }) => (
                profile ? { name, username, email } : { name, username, email, is_admin }
            ));
            form.patch(editingUser.update_url, { preserveScroll: true, onSuccess: onClose });
        } else {
            form.post('/usuarios', { onSuccess: () => form.reset() });
        }
    }

    return (
        <form className={`flex flex-col gap-4 ${editingUser ? '' : 'p-5'}`} onSubmit={submit}>
            <Field error={form.errors.name} label="Nombre">
                <input className={inputClassName} onChange={(event) => form.setData('name', event.target.value)} required value={form.data.name} />
            </Field>
            <Field error={form.errors.username} label="Nombre de usuario">
                <input
                    autoCapitalize="none"
                    autoComplete="username"
                    className={inputClassName}
                    maxLength={100}
                    onChange={(event) => form.setData('username', event.target.value)}
                    pattern="[A-Za-z0-9_.\-]+"
                    required
                    title="Usa letras sin tildes, números, punto, guion o guion bajo, sin espacios."
                    value={form.data.username}
                />
                <Hint>Sin espacios. Usa letras sin tildes, números, punto, guion o guion bajo.</Hint>
            </Field>
            <Field error={form.errors.email} label="Correo">
                <input autoComplete="email" className={inputClassName} onChange={(event) => form.setData('email', event.target.value)} required type="email" value={form.data.email} />
            </Field>
            {!editingUser && (
                <>
                    <Field error={form.errors.password} label="Contraseña inicial">
                        <PasswordInput autoComplete="new-password" description="contraseña inicial" minLength={12} onChange={(event) => form.setData('password', event.target.value)} required value={form.data.password} />
                        <Hint>Mínimo 12 caracteres. Compártela con el usuario por un canal seguro.</Hint>
                    </Field>
                    <Field error={form.errors.password_confirmation} label="Confirmar contraseña">
                        <PasswordInput autoComplete="new-password" description="confirmación de contraseña" minLength={12} onChange={(event) => form.setData('password_confirmation', event.target.value)} required value={form.data.password_confirmation} />
                    </Field>
                </>
            )}
            {!profile && <Field error={form.errors.is_admin} label={
                <span className="flex items-center gap-2">
                    <input checked={form.data.is_admin} className="size-4 accent-prosof-action" onChange={(event) => form.setData('is_admin', event.target.checked)} type="checkbox" />
                    Cuenta de administrador
                </span>
            }>
                <Hint>Esta cuenta podrá administrar usuarios.</Hint>
            </Field>}
            <div className="flex flex-wrap gap-2">
                {editingUser && <button className={secondaryButton} disabled={form.processing} onClick={onClose} type="button">Cancelar</button>}
                <button className={primaryButton} disabled={form.processing} type="submit">
                    {form.processing ? 'Guardando…' : editingUser ? 'Guardar cambios' : 'Crear usuario'}
                </button>
            </div>
        </form>
    );
}

function UserAccessButton({ user }) {
    const form = useForm({ is_active: !user.is_active });

    async function toggle() {
        const confirmed = await confirmAction({
            title: user.is_active ? '¿Desactivar esta cuenta?' : '¿Reactivar esta cuenta?',
            text: user.is_active
                ? `${user.name} perderá acceso al panel. Su historial se conservará.`
                : `${user.name} podrá volver a iniciar sesión.`,
            confirmText: user.is_active ? 'Desactivar' : 'Reactivar',
            danger: user.is_active,
        });

        if (confirmed) {
            form.transform(() => ({ is_active: !user.is_active }));
            form.patch(user.active_url, { preserveScroll: true });
        }
    }

    return (
        <button className={user.is_active ? dangerButton : `${secondaryButton} px-3! py-1.5! text-xs!`} disabled={form.processing} onClick={toggle} type="button">
            {user.is_active ? 'Desactivar' : 'Reactivar'}
        </button>
    );
}

function UsersManagement({ users }) {
    const [editingUser, setEditingUser] = useState(null);
    const closeEdit = () => setEditingUser(null);

    return (
        <div className="grid gap-5 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
            <Card title="Crear usuario"><UserForm /></Card>
            {editingUser && (
                <Modal onClose={closeEdit} title={`Editar usuario · ${editingUser.name}`}>
                    <UserForm editingUser={editingUser} key={editingUser.id} onClose={closeEdit} />
                </Modal>
            )}
            <Card title={`Usuarios (${users.length})`}>
                {users.length === 0 ? <EmptyState>No hay usuarios registrados.</EmptyState> : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-700">
                            <thead className="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th className="px-4 py-3 font-semibold" scope="col">Usuario</th>
                                    <th className="px-4 py-3 font-semibold" scope="col">Correo</th>
                                    <th className="px-4 py-3 font-semibold" scope="col">Acceso</th>
                                    <th className="px-4 py-3 font-semibold" scope="col">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {users.map((listedUser) => (
                                    <tr key={listedUser.id}>
                                        <td className="px-4 py-3">
                                            <p className="font-semibold text-slate-800 dark:text-slate-100">{listedUser.name}</p>
                                            <p className="text-xs text-slate-500 dark:text-slate-400">{listedUser.username}</p>
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-3 text-slate-600 dark:text-slate-300">{listedUser.email}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-col items-start gap-1">
                                                <Badge tone={listedUser.is_admin ? 'info' : 'neutral'}>{listedUser.is_admin ? 'Administrador' : 'Usuario'}</Badge>
                                                <Badge tone={listedUser.is_active ? 'success' : 'neutral'}>{listedUser.is_active ? 'Activa' : 'Desactivada'}</Badge>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap gap-2">
                                                <button className={`${secondaryButton} px-3! py-1.5! text-xs!`} onClick={() => setEditingUser(listedUser)} type="button">Editar</button>
                                                <UserAccessButton user={listedUser} />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
        </div>
    );
}

export default function Panel({ agentDownloads, appVersion, canManageUsers = false, deploymentFilters, deploymentHistory, deployments, deploymentTimezone, passwordUpdateUrl, profileUpdateUrl, projects, projectTagsSyncAllUrl, section, sectionUrls, servers, user, users = [] }) {
    const [modal, setModal] = useState(null);
    const [tokenReveal, setTokenReveal] = useState(null);
    const [menuOpen, setMenuOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(() => typeof window !== 'undefined' && window.localStorage.getItem(SIDEBAR_KEY) === '1');
    const logoutForm = useForm({});

    async function logout() {
        const confirmed = await confirmAction({ title: '¿Cerrar sesión?', text: 'Tendrá que ingresar de nuevo para continuar.', confirmText: 'Salir' });
        if (confirmed) {
            logoutForm.post('/logout');
        }
    }

    useEffect(() => {
        const removeSuccess = router.on('success', (event) => {
            if (event.detail.page.flash?.agent_token) {
                setTokenReveal(event.detail.page.flash.agent_token);
            }
            const message = event.detail.page.props.flash?.success;
            if (message) {
                notifySuccess(message);
            }
            const errorMessage = event.detail.page.props.flash?.error;
            if (errorMessage) {
                notifyError(errorMessage);
            }
        });
        const removeError = router.on('error', (event) => {
            const messages = [...new Set(Object.values(event.detail.errors ?? {}).filter(Boolean))];
            if (messages.length > 0) {
                notifyError(messages);
            }
        });
        const removeException = router.on('exception', () => notifyError('No se pudo completar la acción. Verifique la conexión e intente de nuevo.'));

        return () => {
            removeSuccess();
            removeError();
            removeException();
        };
    }, []);
    const closeModal = () => setModal(null);

    useEffect(() => {
        window.localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0');
    }, [collapsed]);

    useEffect(() => {
        if (!menuOpen) {
            return undefined;
        }
        const onKey = (event) => event.key === 'Escape' && setMenuOpen(false);
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [menuOpen]);

    const targets = deployments.flatMap((deployment) => deployment.targets);
    const pending = targets.filter((target) => target.status === 'pending').length;
    const problems = targets.filter((target) => ['failed', 'uncertain'].includes(target.status)).length;
    const visibleSections = sections.filter((item) => item.key !== 'users' || canManageUsers);
    const current = visibleSections.find((item) => item.key === section);

    const headerAction = {
        summary: { label: 'Programar despliegue', modal: { type: 'deployment' } },
        deployments: { label: 'Programar despliegue', modal: { type: 'deployment' } },
        projects: { label: 'Nuevo', modal: { type: 'project' } },
        servers: { label: 'Nuevo servidor', modal: { type: 'server' } },
    }[section];

    return (
        <>
            <Head title="Panel de despliegues" />
            <div className="panel-backdrop min-h-screen text-slate-900 dark:text-slate-100">
                <header className="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-green-900/10 bg-white/55 px-4 backdrop-blur-[30px] backdrop-saturate-175 sm:gap-5 sm:px-6 dark:border-white/10 dark:bg-[#142118]/80">
                    <button aria-controls="panel-navigation" aria-expanded={menuOpen} aria-label={menuOpen ? 'Cerrar menú' : 'Abrir menú'} className="grid size-10 shrink-0 place-items-center rounded-full bg-white/80 text-slate-700 transition hover:bg-white dark:bg-white/10 dark:text-slate-100 dark:hover:bg-white/20 lg:hidden" onClick={() => setMenuOpen(!menuOpen)} type="button">
                        <Icon name={menuOpen ? 'close' : 'menu'} />
                    </button>
                    <button aria-controls="panel-navigation" aria-expanded={!collapsed} aria-label={collapsed ? 'Expandir menú' : 'Contraer menú'} className="hidden size-10 shrink-0 place-items-center rounded-full bg-white/80 text-slate-700 transition hover:bg-white dark:bg-white/10 dark:text-slate-100 dark:hover:bg-white/20 lg:grid" onClick={() => setCollapsed(!collapsed)} type="button">
                        <Icon name={collapsed ? 'menu' : 'close'} />
                    </button>
                    <img alt="Prosof" className="h-8 w-auto max-w-[45%] object-contain" src="/img/prosof-logo.webp" />
                    <p className="hidden border-l border-green-900/15 pl-5 text-sm text-slate-600 sm:block dark:border-white/15 dark:text-slate-300">Panel de despliegues <span className="font-semibold text-slate-900 dark:text-white">:: Prosof SAS</span></p>
                    <div className="ml-auto flex items-center gap-3">
                        <ThemeToggle />
                        <UserMenu
                            onChangePassword={() => setModal({ type: 'password' })}
                            onEditProfile={() => setModal({ type: 'profile' })}
                            onLogout={logout}
                            processing={logoutForm.processing}
                            user={user}
                        />
                    </div>
                </header>

                {menuOpen && <div aria-hidden="true" className="fixed inset-0 z-40 bg-neutral-900/50 lg:hidden" onClick={() => setMenuOpen(false)} />}

                <div className="lg:flex lg:items-start lg:gap-4 lg:px-4 lg:pb-4">
                <aside id="panel-navigation" className={`fixed bottom-4 left-4 top-4 z-50 flex w-72 max-w-[calc(100vw-2rem)] flex-col rounded-2xl border border-white/80 bg-white/55 shadow-xl backdrop-blur-[30px] backdrop-saturate-175 dark:border-white/15 dark:bg-[#17251a]/95 lg:sticky lg:bottom-auto lg:left-auto lg:top-20 lg:z-20 lg:mt-4 lg:h-[calc(100vh-6rem)] lg:shrink-0 lg:shadow-sm ${menuOpen ? 'visible translate-x-0' : 'invisible -translate-x-[110%] lg:visible lg:translate-x-0'} ${collapsed ? 'lg:w-[72px]' : 'lg:w-[210px]'}`}>
                    <div className="flex items-center justify-between px-4 pt-4 lg:hidden">
                        <span className="font-display text-sm font-bold text-slate-700 dark:text-slate-200">Navegación</span>
                        <button aria-label="Cerrar menú" className="rounded-lg p-2 text-slate-700 transition hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-white/10" onClick={() => setMenuOpen(false)} type="button">
                            <Icon name="close" />
                        </button>
                    </div>
                    <nav aria-label="Navegación principal" className="flex-1 space-y-1 overflow-y-auto px-2 pt-3">
                        {visibleSections.map((item) => (
                            <Link
                                aria-current={section === item.key ? 'page' : undefined}
                                aria-label={item.label}
                                className={`flex w-full items-center gap-3 whitespace-nowrap rounded-xl px-3 py-3 text-sm font-semibold transition ${collapsed ? 'lg:justify-center lg:px-0' : ''} ${section === item.key ? 'bg-prosof-lime/20 text-prosof-ink ring-1 ring-prosof-dark/30 dark:bg-prosof-lime/15 dark:text-prosof-lime dark:ring-prosof-lime/30' : 'text-slate-600 hover:bg-green-900/5 dark:text-slate-300 dark:hover:bg-white/10'}`}
                                href={sectionUrls[item.key]}
                                key={item.key}
                                onClick={() => setMenuOpen(false)}
                                title={collapsed ? item.label : undefined}
                            >
                                <Icon className={`size-5 shrink-0 ${section === item.key ? 'text-prosof-dark dark:text-prosof-lime' : ''}`} name={item.icon} />
                                <span className={collapsed ? 'lg:hidden' : ''}>{item.label}</span>
                            </Link>
                        ))}
                    </nav>
                    <div className="px-2 pb-3">
                        <p aria-label={`Versión ${appVersion}`} className="break-all px-3 py-2 text-center text-xs text-slate-500 dark:text-slate-400" title={`Versión ${appVersion}`}>
                            Versión: {appVersion}
                        </p>
                    </div>
                </aside>

                <div className="min-w-0 flex-1 p-4 lg:px-0 lg:pb-0">
                    <main className="mx-auto max-w-6xl space-y-5 rounded-2xl border border-white/80 bg-white/55 px-4 py-5 shadow-sm backdrop-blur-[30px] backdrop-saturate-175 sm:px-6 dark:border-white/15 dark:bg-[#17251a]/75">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h1 className="font-display text-xl font-extrabold text-neutral-800 dark:text-slate-100">{current.label}</h1>
                                <p className="hidden text-sm text-slate-600 sm:block dark:text-slate-300">¡Soluciones que evolucionan!</p>
                            </div>
                            {headerAction && (
                                <button aria-label={section === 'projects' ? 'Crear proyecto' : undefined} className={`${primaryButton} max-sm:w-full`} onClick={() => setModal(headerAction.modal)} type="button">
                                    <Icon className="size-4" name="plus" />{headerAction.label}
                                </button>
                            )}
                        </div>
                        {section === 'summary' && (
                            <>
                                <section className="panel-hero rounded-2xl border-l-4 border-prosof-lime px-5 py-6 shadow-sm ring-1 ring-green-900/10 sm:px-7 dark:ring-white/10">
                                    <p className="text-xs font-bold uppercase tracking-widest text-prosof-ink dark:text-prosof-lime">Panel de despliegues</p>
                                    <h2 className="mt-2 text-2xl font-extrabold tracking-tight text-neutral-800 dark:text-white">Control de despliegues</h2>
                                    <p className="mt-2 text-sm text-slate-700 dark:text-slate-300">Consulta el estado de tus proyectos, servidores y programaciones en un solo lugar.</p>
                                </section>
                                <div className="flex gap-3 rounded-xl border-l-4 border-amber-500 bg-amber-50 dark:bg-amber-950/60 px-4 py-3 text-sm text-amber-950 dark:text-amber-100">
                                    <Icon className="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-300" name="alert" />
                                    <p><strong>Validación pendiente:</strong> el agente requiere HTTPS y pruebas en Windows antes de usarse en producción. Si el commit cambia una ruta protegida, se bloqueará el despliegue.</p>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                    <StatCard icon="folder" label="Proyectos" tone="bg-prosof-lime/25 text-prosof-dark dark:text-prosof-lime" value={projects.length} />
                                    <StatCard icon="server" label="Servidores en línea" tone="bg-sky-50 dark:bg-sky-950/60 text-sky-700 dark:text-sky-300" value={`${servers.filter(isOnline).length}/${servers.length}`} />
                                    <StatCard icon="rocket" label="Trabajos pendientes" tone="bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-300" value={pending} />
                                    <StatCard icon="alert" label="Fallidos o inciertos" tone="bg-red-50 dark:bg-red-950/60 text-red-600 dark:text-red-300" value={problems} />
                                </div>
                                <div className="grid gap-5 xl:grid-cols-3">
                                    <div className="xl:col-span-2">
                                        <Card action={<Link className="text-sm font-semibold text-prosof-dark dark:text-prosof-lime hover:underline" href={sectionUrls.deployments}>Ver todas</Link>} title="Últimas programaciones">
                                            <DeploymentsTable deployments={deployments} limit={5} />
                                        </Card>
                                    </div>
                                    <Card title="Estado de servidores"><ServersList servers={servers} /></Card>
                                </div>
                            </>
                        )}

                        {section === 'deployments' && <DeploymentHistory filters={deploymentFilters} history={deploymentHistory} projects={projects} servers={servers} url={sectionUrls.deployments} />}

                        {section === 'projects' && (
                            <ProjectsGrid
                                onAssign={(projectId) => setModal({ type: 'assignment', projectId })}
                                onScheduleUpdate={(projectId, version, serverIds) => setModal({
                                    type: 'deployment',
                                    projectId,
                                    ref: `refs/tags/${version}`,
                                    serverIds,
                                })}
                                onSsh={(project) => setModal({ type: 'ssh', project })}
                                projects={projects}
                                syncAllUrl={projectTagsSyncAllUrl}
                            />
                        )}

                        {section === 'servers' && (
                            <Card title={`Servidores (${servers.length})`}><ServersList servers={servers} showActions /></Card>
                        )}

                        {section === 'downloads' && (
                            <WindowsAgentDownloads downloads={agentDownloads} onOpenGuide={() => setModal({ type: 'windows-agent-guide' })} />
                        )}

                        {section === 'users' && <UsersManagement users={users} />}
                    </main>
                </div>
                </div>
            </div>

            {tokenReveal && <TokenModal onClose={() => setTokenReveal(null)} reveal={tokenReveal} />}
            {modal?.type === 'password' && <PasswordModal onClose={closeModal} updateUrl={passwordUpdateUrl} />}
            {modal?.type === 'profile' && (
                <Modal onClose={closeModal} title="Editar perfil">
                    <UserForm editingUser={{ ...user, update_url: profileUpdateUrl }} onClose={closeModal} profile />
                </Modal>
            )}
            {modal?.type === 'deployment' && <DeploymentModal initialProjectId={modal.projectId ?? ''} initialRef={modal.ref ?? ''} initialServerIds={modal.serverIds ?? []} onClose={closeModal} projects={projects.filter((project) => project.is_active)} servers={servers.filter((server) => server.is_active)} timezone={deploymentTimezone} />}
            {modal?.type === 'project' && <ProjectModal onClose={closeModal} />}
            {modal?.type === 'server' && <ServerModal onClose={closeModal} />}
            {modal?.type === 'assignment' && <AssignmentModal initialProjectId={modal.projectId} onClose={closeModal} projects={projects} servers={servers} />}
            {modal?.type === 'ssh' && <SshModal onClose={closeModal} project={modal.project} />}
            {modal?.type === 'windows-agent-guide' && <WindowsAgentGuideModal onClose={closeModal} />}
        </>
    );
}
