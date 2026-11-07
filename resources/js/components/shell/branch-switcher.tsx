import { usePage, router } from '@inertiajs/react';
import { Check, ChevronsUpDown, Store } from 'lucide-react';
import { Kbd } from '@/components/kbd';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useUiStore } from '@/command/ui-store';
import { useCan } from '@/hooks/use-can';
import { useT } from '@/i18n';

/** POST /app/branch/{branch}/switch (route #7, lands with D3.2). Shown only with more than one branch. */
const switchUrl = (id: number) => `/app/branch/${id}/switch`;

export function BranchSwitcher() {
    const { t } = useT();
    const can = useCan();
    const { branches, branch_id } = usePage().props.auth;
    const open = useUiStore((s) => s.overlay === 'branch');
    const toggle = useUiStore((s) => s.toggle);
    const close = useUiStore((s) => s.close);

    const current = branches.find((b) => b.id === branch_id);

    // Only users with branches.switch (the Owner) may change branch; everyone else sees their own as plain text.
    if (!can('branches.switch')) {
        return current ? (
            <span
                className="inline-flex min-h-11 max-w-48 items-center gap-2 px-2 text-sm md:min-h-9"
                title={t('branch.current')}
            >
                <Store className="size-4 opacity-60" aria-hidden="true" />
                <span className="truncate">{current.name}</span>
            </span>
        ) : null;
    }
    if (branches.length < 2) {
        return null;
    }

    return (
        <DropdownMenu
            open={open}
            onOpenChange={(next) => (next ? toggle('branch') : close())}
        >
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-11 max-w-48 md:min-h-9"
                    aria-label={t('branch.switch')}
                >
                    <Store aria-hidden="true" />
                    <span className="truncate">
                        {current?.name ?? t('branch.choose')}
                    </span>
                    <ChevronsUpDown className="opacity-60" aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="min-w-56">
                {branches.map((branch) => (
                    <DropdownMenuItem
                        key={branch.id}
                        className="min-h-11 md:min-h-8"
                        onSelect={() => {
                            close();
                            // Full visit to the dashboard: prefetched links may hold
                            // old-branch props, so never reuse them after a switch.
                            router.post(switchUrl(branch.id));
                        }}
                    >
                        <span className="flex-1 truncate">{branch.name}</span>
                        {branch.id === branch_id ? (
                            <Check aria-hidden="true" />
                        ) : null}
                    </DropdownMenuItem>
                ))}
                <div className="flex justify-end px-2 py-1">
                    <Kbd keys="alt+b" />
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
