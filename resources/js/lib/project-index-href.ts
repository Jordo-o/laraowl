type ProjectIndexProps = {
    currentTeam?: { slug?: string } | null;
    current_team?: { slug?: string } | null;
    currentProject?: { slug?: string } | null;
    current_project?: { slug?: string } | null;
    period?: string | null;
    from?: string | null;
    to?: string | null;
};

export function projectIndexHref(props: ProjectIndexProps, section: string) {
    const team = props.currentTeam?.slug ?? props.current_team?.slug;
    const project = props.currentProject?.slug ?? props.current_project?.slug;

    if (!team || !project) {
        return '/dashboard';
    }

    const query = new URLSearchParams();

    for (const key of ['period', 'from', 'to'] as const) {
        const value = props[key];

        if (value) {
            query.set(key, value);
        }
    }

    const path = `/${encodeURIComponent(team)}/${encodeURIComponent(project)}/${section}`;

    return query.size ? `${path}?${query}` : path;
}
