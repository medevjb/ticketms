import { Head } from '@inertiajs/react';
import type { Auth } from '@/types/auth';
import { create as createCustomerTicket } from '@/actions/App/Http/Controllers/Customer/TicketController';

type SlaOverview = {
    timezone: string;
    windows: { day: string; hours: string }[];
    targets: { priority: string; duration: string; due_label: string | null }[];
    availability: {
        is_open: boolean;
        message: string;
        next_open_label: string | null;
    };
} | null;

type WelcomeProps = {
    auth: Auth;
    dashboardUrl: string | null;
    isCustomerAccount: boolean;
    name: string;
    slaOverview: SlaOverview;
};

const steps = [
    {
        number: '01',
        title: 'Tell us what happened',
        description:
            'Choose the closest category, set urgency, and explain the issue in your own words.',
    },
    {
        number: '02',
        title: 'We take it from here',
        description:
            'Your request is organized for the right support workflow and you can follow every update.',
    },
    {
        number: '03',
        title: 'Stay in the loop',
        description:
            'Reply in one place, add useful files, and see the conversation through to resolution.',
    },
];

export default function Welcome({
    auth,
    dashboardUrl,
    isCustomerAccount,
    name,
    slaOverview,
}: WelcomeProps) {
    const primaryHref =
        auth.user && !isCustomerAccount
            ? (dashboardUrl ?? '/')
            : createCustomerTicket.url();
    const primaryLabel =
        auth.user && !isCustomerAccount
            ? 'Open dashboard'
            : 'Create a support ticket';

    return (
        <>
            <Head title="Customer support, made simple" />
            <div className="min-h-screen overflow-hidden bg-[#f8fafc] text-slate-950 selection:bg-cyan-200 selection:text-slate-950">
                <header className="relative z-10 border-b border-slate-200/70 bg-white/90 backdrop-blur">
                    <nav
                        aria-label="Main navigation"
                        className="mx-auto flex max-w-7xl items-center justify-between px-5 py-4 sm:px-8"
                    >
                        <a
                            href="/"
                            className="flex items-center gap-3"
                            aria-label={`${name} home`}
                        >
                            <span className="grid size-10 place-items-center rounded-2xl bg-slate-950 text-cyan-300 shadow-lg shadow-slate-900/10">
                                <svg
                                    aria-hidden="true"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    className="size-5"
                                >
                                    <path
                                        d="M5 12a7 7 0 0 1 14 0v5a2 2 0 0 1-2 2h-2v-6h4M5 13h4v6H7a2 2 0 0 1-2-2v-4Z"
                                        stroke="currentColor"
                                        strokeWidth="1.8"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                    />
                                </svg>
                            </span>
                            <span className="text-lg font-bold tracking-tight">
                                {name}
                            </span>
                        </a>
                        <div className="hidden items-center gap-8 text-sm font-medium text-slate-600 md:flex">
                            <a
                                className="transition hover:text-slate-950"
                                href="#how-it-works"
                            >
                                How it works
                            </a>
                            <a
                                className="transition hover:text-slate-950"
                                href="#service-levels"
                            >
                                Service levels
                            </a>
                            <a
                                className="transition hover:text-slate-950"
                                href="#hours"
                            >
                                Support hours
                            </a>
                        </div>
                        <div className="flex items-center gap-3">
                            {auth.user ? (
                                <a
                                    href={dashboardUrl ?? '/'}
                                    className="hidden rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 sm:inline-flex"
                                >
                                    Dashboard
                                </a>
                            ) : (
                                <a
                                    href="/login"
                                    className="rounded-xl px-3 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 sm:px-4"
                                >
                                    Sign in
                                </a>
                            )}
                            <a
                                href={primaryHref}
                                className="inline-flex items-center gap-2 rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-md shadow-slate-900/10 transition hover:-translate-y-0.5 hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-600"
                            >
                                <span>{primaryLabel}</span>
                                <span aria-hidden="true">↗</span>
                            </a>
                        </div>
                    </nav>
                </header>

                <main>
                    <section className="relative isolate">
                        <div
                            aria-hidden="true"
                            className="absolute inset-0 -z-10 bg-[radial-gradient(ellipse_at_top_right,_rgba(34,211,238,0.18),_transparent_42%),radial-gradient(ellipse_at_bottom_left,_rgba(129,140,248,0.12),_transparent_38%)]"
                        />
                        <div className="mx-auto grid max-w-7xl items-center gap-14 px-5 py-16 sm:px-8 sm:py-24 lg:grid-cols-[1.05fr_.95fr] lg:gap-20 lg:py-28">
                            <div>
                                <div className="inline-flex items-center gap-2 rounded-full border border-cyan-200 bg-white/80 px-3.5 py-2 text-xs font-bold tracking-wide text-cyan-900 shadow-sm">
                                    <span className="size-2 rounded-full bg-emerald-500 ring-4 ring-emerald-100" />{' '}
                                    SUPPORT THAT KEEPS YOU MOVING
                                </div>
                                <h1 className="mt-7 max-w-2xl text-5xl leading-[1.06] font-semibold tracking-[-0.045em] text-slate-950 sm:text-6xl lg:text-[4.4rem]">
                                    Help is closer than{' '}
                                    <span className="text-cyan-700">
                                        you think.
                                    </span>
                                </h1>
                                <p className="mt-6 max-w-xl text-lg leading-8 text-slate-600 sm:text-xl">
                                    A clear, dependable place to ask for help,
                                    share the details, and stay informed until
                                    your request is resolved.
                                </p>
                                <div className="mt-9 flex flex-col gap-3 sm:flex-row">
                                    <a
                                        href={primaryHref}
                                        className="inline-flex items-center justify-center gap-3 rounded-2xl bg-cyan-700 px-6 py-4 text-base font-bold text-white shadow-xl shadow-cyan-900/20 transition hover:-translate-y-0.5 hover:bg-cyan-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-700"
                                    >
                                        {primaryLabel}
                                        <span aria-hidden="true">→</span>
                                    </a>
                                    <a
                                        href="#how-it-works"
                                        className="inline-flex items-center justify-center rounded-2xl border border-slate-300 bg-white px-6 py-4 text-base font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"
                                    >
                                        See how it works
                                    </a>
                                </div>
                                <div className="mt-10 flex flex-wrap items-center gap-x-7 gap-y-3 border-t border-slate-200 pt-6 text-sm text-slate-500">
                                    <span className="inline-flex items-center gap-2">
                                        <span className="text-emerald-600">
                                            ✓
                                        </span>{' '}
                                        One place for every update
                                    </span>
                                    <span className="inline-flex items-center gap-2">
                                        <span className="text-emerald-600">
                                            ✓
                                        </span>{' '}
                                        Clear service expectations
                                    </span>
                                </div>
                            </div>

                            <div className="relative mx-auto w-full max-w-xl">
                                <div
                                    aria-hidden="true"
                                    className="absolute -inset-5 rounded-[2.5rem] bg-gradient-to-br from-cyan-200/70 via-blue-100/60 to-indigo-200/60 blur-2xl"
                                />
                                <div className="relative overflow-hidden rounded-[2rem] border border-white/80 bg-slate-950 p-5 text-white shadow-2xl shadow-slate-900/20 sm:p-7">
                                    <div className="flex items-center justify-between border-b border-white/10 pb-5">
                                        <div>
                                            <p className="text-xs font-semibold tracking-[.18em] text-cyan-300 uppercase">
                                                Support workspace
                                            </p>
                                            <p className="mt-2 text-xl font-semibold">
                                                Your request, in focus
                                            </p>
                                        </div>
                                        <span className="grid size-11 place-items-center rounded-2xl bg-white/10 text-cyan-200">
                                            <svg
                                                aria-hidden="true"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                className="size-5"
                                            >
                                                <path
                                                    d="M7 8h10M7 12h7m-9 8 2.2-3H18a3 3 0 0 0 3-3V6a3 3 0 0 0-3-3H6a3 3 0 0 0-3 3v8a3 3 0 0 0 3 3h1"
                                                    stroke="currentColor"
                                                    strokeWidth="1.7"
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                />
                                            </svg>
                                        </span>
                                    </div>
                                    <div className="mt-6 rounded-2xl border border-white/10 bg-white/[.06] p-5">
                                        <div className="flex items-center justify-between gap-4">
                                            <span className="text-sm font-semibold">
                                                Request progress
                                            </span>
                                            <span className="rounded-full bg-emerald-300/15 px-3 py-1 text-xs font-semibold text-emerald-200">
                                                In progress
                                            </span>
                                        </div>
                                        <div className="mt-6 grid grid-cols-3 gap-2">
                                            {[
                                                'Received',
                                                'In review',
                                                'Resolved',
                                            ].map((label, index) => (
                                                <div key={label}>
                                                    <div
                                                        className={`h-1.5 rounded-full ${index < 2 ? 'bg-cyan-300' : 'bg-white/15'}`}
                                                    />
                                                    <p
                                                        className={`mt-2 text-xs ${index < 2 ? 'text-white' : 'text-slate-400'}`}
                                                    >
                                                        {label}
                                                    </p>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                    <div className="mt-4 grid grid-cols-2 gap-4">
                                        <div className="rounded-2xl bg-cyan-300 p-5 text-slate-950">
                                            <p className="text-xs font-bold tracking-wider text-slate-700 uppercase">
                                                Stay informed
                                            </p>
                                            <p className="mt-3 text-lg font-bold">
                                                Updates in one conversation
                                            </p>
                                        </div>
                                        <div className="rounded-2xl border border-white/10 bg-white/[.06] p-5">
                                            <p className="text-xs font-bold tracking-wider text-cyan-200 uppercase">
                                                Know what to expect
                                            </p>
                                            <p className="mt-3 text-lg font-bold">
                                                Service targets by priority
                                            </p>
                                        </div>
                                    </div>
                                    <p className="mt-5 text-center text-xs text-slate-400">
                                        A thoughtful support experience, from
                                        first message to resolution.
                                    </p>
                                </div>
                                <div className="absolute -bottom-5 -left-5 hidden items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl sm:flex">
                                    <span className="grid size-10 place-items-center rounded-xl bg-emerald-50 text-xl text-emerald-700">
                                        ✓
                                    </span>
                                    <span>
                                        <strong className="block text-sm text-slate-900">
                                            Every step is traceable
                                        </strong>
                                        <span className="text-xs text-slate-500">
                                            Your conversation stays together
                                        </span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section
                        id="how-it-works"
                        className="scroll-mt-20 bg-white py-20 sm:py-24"
                    >
                        <div className="mx-auto max-w-7xl px-5 sm:px-8">
                            <div className="mx-auto max-w-2xl text-center">
                                <p className="text-sm font-bold tracking-[.18em] text-cyan-700 uppercase">
                                    A simple path forward
                                </p>
                                <h2 className="mt-4 text-3xl font-semibold tracking-tight sm:text-4xl">
                                    Support without the guesswork
                                </h2>
                                <p className="mt-4 text-lg leading-8 text-slate-600">
                                    Share what you need, follow the
                                    conversation, and know where your request
                                    stands.
                                </p>
                            </div>
                            <div className="mt-14 grid gap-5 md:grid-cols-3">
                                {steps.map((step) => (
                                    <article
                                        key={step.number}
                                        className="rounded-3xl border border-slate-200 bg-slate-50/70 p-7 transition hover:-translate-y-1 hover:border-cyan-200 hover:bg-white hover:shadow-xl hover:shadow-slate-900/5"
                                    >
                                        <span className="text-sm font-bold tracking-[.2em] text-cyan-700">
                                            {step.number}
                                        </span>
                                        <h3 className="mt-6 text-xl font-bold tracking-tight">
                                            {step.title}
                                        </h3>
                                        <p className="mt-3 leading-7 text-slate-600">
                                            {step.description}
                                        </p>
                                    </article>
                                ))}
                            </div>
                        </div>
                    </section>

                    <section
                        id="service-levels"
                        className="scroll-mt-20 bg-slate-950 py-20 text-white sm:py-24"
                    >
                        <div className="mx-auto grid max-w-7xl gap-12 px-5 sm:px-8 lg:grid-cols-[.85fr_1.15fr] lg:items-center">
                            <div>
                                <p className="text-sm font-bold tracking-[.18em] text-cyan-300 uppercase">
                                    Clear service expectations
                                </p>
                                <h2 className="mt-4 text-3xl font-semibold tracking-tight sm:text-4xl">
                                    Know when to expect progress.
                                </h2>
                                <p className="mt-5 max-w-xl text-lg leading-8 text-slate-300">
                                    Resolution targets are based on ticket
                                    priority and count during scheduled business
                                    hours. The support team can keep you updated
                                    as work progresses.
                                </p>
                                {slaOverview && (
                                    <div
                                        className={`mt-6 rounded-xl border p-4 ${slaOverview.availability.is_open ? 'border-cyan-300/20 bg-cyan-300/10 text-cyan-100' : 'border-amber-300/20 bg-amber-300/10 text-amber-100'}`}
                                    >
                                        <p className="text-sm font-semibold">
                                            {slaOverview.availability.message}
                                        </p>
                                        <p className="mt-2 text-xs leading-6">
                                            {slaOverview.availability.is_open
                                                ? 'New requests count toward their resolution SLA during service hours.'
                                                : slaOverview.availability
                                                        .next_open_label
                                                  ? `You can submit now. Service resumes ${slaOverview.availability.next_open_label} · ${slaOverview.timezone}. The SLA clock excludes non-working time.`
                                                  : 'You can submit a request now; the applicable target will be confirmed after submission.'}
                                        </p>
                                    </div>
                                )}
                                {slaOverview && (
                                    <div
                                        id="hours"
                                        className="mt-8 rounded-2xl border border-white/10 bg-white/[.06] p-5"
                                    >
                                        <p className="text-sm font-bold text-white">
                                            Business hours{' '}
                                            <span className="font-normal text-slate-400">
                                                · {slaOverview.timezone}
                                            </span>
                                        </p>
                                        <div className="mt-4 flex flex-wrap gap-2">
                                            {slaOverview.windows.map(
                                                (window) => (
                                                    <span
                                                        key={window.day}
                                                        className="rounded-full border border-white/10 bg-white/[.06] px-3 py-1.5 text-xs font-medium text-slate-200"
                                                    >
                                                        {window.day}{' '}
                                                        <span className="text-cyan-200">
                                                            {window.hours}
                                                        </span>
                                                    </span>
                                                ),
                                            )}
                                        </div>
                                        <p className="mt-4 text-sm leading-6 text-slate-400">
                                            Configured holidays are excluded.
                                            When a ticket is waiting for your
                                            reply, its SLA clock pauses and
                                            resumes after you respond.
                                        </p>
                                    </div>
                                )}
                            </div>
                            <div className="rounded-3xl border border-white/10 bg-white/[.05] p-5 sm:p-7">
                                <div className="flex flex-wrap items-end justify-between gap-3 border-b border-white/10 pb-5">
                                    <div>
                                        <p className="text-lg font-bold">
                                            Resolution targets
                                        </p>
                                        <p className="mt-1 text-sm text-slate-400">
                                            Published targets for each active
                                            priority
                                        </p>
                                    </div>
                                    <span className="rounded-full bg-cyan-300/10 px-3 py-1 text-xs font-semibold text-cyan-200">
                                        Business time
                                    </span>
                                </div>
                                {slaOverview &&
                                slaOverview.targets.length > 0 ? (
                                    <div className="mt-2 divide-y divide-white/10">
                                        {slaOverview.targets.map((target) => (
                                            <div
                                                key={target.priority}
                                                className="flex items-center justify-between gap-4 py-4"
                                            >
                                                <span className="font-medium text-slate-200">
                                                    {target.priority}
                                                    {target.due_label && (
                                                        <small className="mt-1 block text-xs leading-5 font-normal text-slate-400">
                                                            If submitted now:{' '}
                                                            {target.due_label} ·{' '}
                                                            {
                                                                slaOverview.timezone
                                                            }
                                                        </small>
                                                    )}
                                                </span>
                                                <span className="rounded-xl bg-white/10 px-3.5 py-2 text-sm font-bold text-white">
                                                    {target.duration}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="py-8 text-sm leading-6 text-slate-300">
                                        Service targets are currently being
                                        updated. The applicable target will be
                                        shown when you create an authorized
                                        ticket.
                                    </p>
                                )}
                                <p className="mt-4 rounded-2xl bg-cyan-300/10 p-4 text-sm leading-6 text-cyan-100">
                                    These are resolution targets, not a promise
                                    of an immediate response. Your ticket page
                                    will show its applicable target and current
                                    status.
                                </p>
                            </div>
                        </div>
                    </section>

                    <section className="bg-cyan-700 px-5 py-16 text-white sm:px-8 sm:py-20">
                        <div className="mx-auto flex max-w-7xl flex-col items-start justify-between gap-7 md:flex-row md:items-center">
                            <div>
                                <p className="text-sm font-bold tracking-[.18em] text-cyan-100 uppercase">
                                    Ready when you are
                                </p>
                                <h2 className="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">
                                    Let’s get your request moving.
                                </h2>
                                <p className="mt-3 max-w-2xl text-cyan-50">
                                    Create a ticket and keep all the details and
                                    updates together.
                                </p>
                            </div>
                            <a
                                href={primaryHref}
                                className="inline-flex shrink-0 items-center gap-3 rounded-2xl bg-white px-6 py-4 font-bold text-cyan-900 shadow-lg transition hover:-translate-y-0.5 hover:bg-cyan-50"
                            >
                                {primaryLabel}
                                <span aria-hidden="true">→</span>
                            </a>
                        </div>
                    </section>
                </main>
                <footer className="bg-white">
                    <div className="mx-auto flex max-w-7xl flex-col gap-3 px-5 py-7 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-8">
                        <span>
                            © {new Date().getFullYear()} {name}. Support, made
                            clear.
                        </span>
                        <div className="flex gap-5">
                            <a className="hover:text-slate-900" href="/login">
                                Sign in
                            </a>
                            <a
                                className="hover:text-slate-900"
                                href="#service-levels"
                            >
                                Service levels
                            </a>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}
