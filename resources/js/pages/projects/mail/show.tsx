import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowUpRight, Mail } from 'lucide-react';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { projectIndexHref } from '@/lib/project-index-href';
import { formatValue } from '@/lib/utils';
import { show as showRecord } from '@/routes/records';

export default function MailShow({
    hash,
    meta,
    records,
}: {
    hash: string;
    meta: any;
    records: any;
}) {
    const { props }: any = usePage();
    const teamSlug = props.current_team?.slug || props.currentTeam?.slug;
    const projectSlug =
        props.current_project?.slug || props.currentProject?.slug;

    const mailableClass =
        meta?.mailable_class ||
        meta?.class ||
        meta?.mailable ||
        meta?.name ||
        'Unknown Mailable';
    const recordHref = (record: number) =>
        showRecord.url(
            {
                current_team: teamSlug,
                project: projectSlug,
                record,
            },
            { mergeQuery: {} },
        );

    return (
        <>
            <Head title={`Mail - ${mailableClass}`} />

            <div className="space-y-8">
                {/* Back Link */}
                <div className="flex items-center gap-2"></div>

                {/* Header Section */}
                <div className="relative flex items-center gap-4 overflow-hidden rounded-lg border border-border bg-card p-6 shadow-2xl">
                    <div className="absolute top-0 right-0 p-4 font-mono text-[10px] text-muted-foreground">
                        HASH: {hash}
                    </div>
                    <div className="rounded-lg border border-border bg-muted p-3">
                        <Mail className="h-6 w-6 text-foreground" />
                    </div>
                    <div className="flex-1">
                        <h1
                            className="max-w-4xl truncate font-mono text-xl font-bold text-foreground"
                            title={mailableClass}
                        >
                            {mailableClass}
                        </h1>
                        <div className="mt-1 flex items-center gap-2">
                            <Badge
                                variant="outline"
                                className="border-border bg-muted text-[10px] font-bold text-foreground uppercase"
                            >
                                {meta?.mailer
                                    ? `MAILER: ${meta.mailer.toUpperCase()}`
                                    : 'APP\\MAIL'}
                            </Badge>
                        </div>
                    </div>
                </div>

                <div className="overflow-hidden rounded-lg border border-border bg-card shadow-2xl">
                    <Table>
                        <TableHeader className="bg-muted/30">
                            <TableRow className="border-border text-[10px] font-bold text-muted-foreground uppercase hover:bg-transparent">
                                <TableHead>Sent At</TableHead>
                                <TableHead>Record</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Subject</TableHead>
                                <TableHead>To / Cc / Bcc</TableHead>
                                <TableHead>Attach.</TableHead>
                                <TableHead>Duration</TableHead>
                                <TableHead className="w-[50px]"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {records.data.map((record: any) => {
                                const p = record.payload ?? {};
                                const isMail = record.type === 'mail';
                                // The client does not send "queued"; execution_source "job" means a queued mailable
                                const queued =
                                    p.queued === true || p.queued === 'true' || p.execution_source === 'job';
                            
                                return (
                                    <TableRow key={record.id} className="group border-border transition-colors hover:bg-muted/30">
                                        <TableCell className="font-mono text-xs text-muted-foreground">
                                            {new Date(record.created_at).toLocaleString()}
                                        </TableCell>
                                        <TableCell className="font-mono text-[10px] text-foreground/50 uppercase">
                                            {record.type}
                                        </TableCell>
                                        <TableCell>
                                            <Badge className={`text-[10px] font-bold uppercase ${queued ? 'bg-blue-500/10 text-blue-400' : 'bg-muted text-foreground/50'} border-none`}>
                                                {queued ? 'QUEUED' : 'DIRECT'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="max-w-xs truncate text-xs text-foreground/70">
                                            {isMail ? (p.subject ?? '-') : '-'}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs text-foreground/70">
                                            {isMail ? `${p.to ?? 0} / ${p.cc ?? 0} / ${p.bcc ?? 0}` : '-'}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs text-foreground/70">
                                            {isMail ? (p.attachments ?? 0) : '-'}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs text-foreground/70">
                                            {/* duration arrives in microseconds */}
                                            {p.duration ? `${Math.round(p.duration / 1000)} ms` : '-'}
                                        </TableCell>
                                        <TableCell>
                                            <Link href={recordHref(record.id)}>
                                                <div className="rounded border border-border bg-muted p-1 transition-all group-hover:border-border">
                                                    <ArrowUpRight className="h-3 w-3" />
                                                </div>
                                            </Link>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                    <Pagination links={records.links} meta={records} />
                </div>
            </div>
        </>
    );
}

MailShow.layout = (props: Parameters<typeof projectIndexHref>[0]) => ({
    breadcrumbs: [
        { title: 'Mail', href: projectIndexHref(props, 'mail') },
        { title: 'Details', href: '#' },
    ],
});
