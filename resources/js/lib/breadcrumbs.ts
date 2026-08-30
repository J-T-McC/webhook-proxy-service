import { formatTimestamp } from '@/lib/format';
import proxyRoutes from '@/routes/proxies';
import proxyEventRoutes from '@/routes/proxies/events';
import type { Team } from '@/types';
import type { BreadcrumbItem } from '@/types/navigation';

/**
 * The proxy pages' breadcrumb trail, built one crumb at a time.
 *
 * Every crumb below is team-scoped, and each page receives `currentTeam` from
 * the layout's own props rather than from `usePage()` — a page's
 * `defineOptions({ layout })` callback runs outside the component instance,
 * so there is no page context to read there. `currentTeam` is nullable in
 * that callback's signature, and a null team falls back to `'/'`: the same
 * behaviour every one of these pages already had, stated here once instead
 * of once per crumb per page.
 */
type CurrentTeam = Team | null | undefined;

type ProxyCrumbSubject = { id: number; name: string };
type EventCrumbSubject = { id: number; received_at: string };

/** The proxy list — the root of every proxy trail. */
export function proxiesCrumb(currentTeam: CurrentTeam): BreadcrumbItem {
    return {
        title: 'Proxies',
        href: currentTeam ? proxyRoutes.index(currentTeam.slug) : '/',
    };
}

/** One proxy, by name, pointing at its Show page. */
export function proxyCrumb(
    currentTeam: CurrentTeam,
    proxy: ProxyCrumbSubject,
): BreadcrumbItem {
    return {
        title: proxy.name,
        href: currentTeam
            ? proxyRoutes.show({
                  current_team: currentTeam.slug,
                  proxy: proxy.id,
              })
            : '/',
    };
}

/** The proxy's edit form. */
export function proxyEditCrumb(
    currentTeam: CurrentTeam,
    proxy: ProxyCrumbSubject,
): BreadcrumbItem {
    return {
        title: 'Edit',
        href: currentTeam
            ? proxyRoutes.edit({
                  current_team: currentTeam.slug,
                  proxy: proxy.id,
              })
            : '/',
    };
}

/** The proxy's event list. */
export function proxyEventsCrumb(
    currentTeam: CurrentTeam,
    proxy: ProxyCrumbSubject,
): BreadcrumbItem {
    return {
        title: 'Events',
        href: currentTeam
            ? proxyEventRoutes.index({
                  current_team: currentTeam.slug,
                  proxy: proxy.id,
              })
            : '/',
    };
}

/** One event, titled by its receipt time — the same formatter the pages use. */
export function proxyEventCrumb(
    currentTeam: CurrentTeam,
    proxy: ProxyCrumbSubject,
    event: EventCrumbSubject,
): BreadcrumbItem {
    return {
        title: formatTimestamp(event.received_at),
        href: currentTeam
            ? proxyEventRoutes.show({
                  current_team: currentTeam.slug,
                  proxy: proxy.id,
                  event: event.id,
              })
            : '/',
    };
}
