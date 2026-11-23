import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

export type IssueStatus = 'open' | 'resolved' | 'ignored';

export function IssueStatusBadge({
    status,
    className,
}: {
    status?: IssueStatus | null;
    className?: string;
}) {
    return (
        <Badge
            variant={status === 'open' ? 'destructive' : 'outline'}
            className={cn(
                'capitalize',
                status === 'resolved' &&
                    'border-emerald-500/20 bg-emerald-500/10 text-emerald-500',
                className,
            )}
        >
            {status ?? 'Status unavailable'}
        </Badge>
    );
}
