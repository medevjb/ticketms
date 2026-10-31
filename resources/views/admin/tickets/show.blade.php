@extends('tyro-dashboard::layouts.admin')
@section('title', $ticket->number)
@section('breadcrumb')<a href="{{ route($dashboardRoute::name('index')) }}">Dashboard</a><span class="breadcrumb-separator">/</span><a href="{{ route('dashboard.tickets.index') }}">Tickets</a><span class="breadcrumb-separator">/</span><span>{{ $ticket->number }}</span>@endsection
@push('styles')
<style>
    .ticket-detail-page{--ticket-ink:#172033;--ticket-muted:#667085;--ticket-line:#e6eaf0;--ticket-surface:#fff;--ticket-soft:#f7f9fc;--ticket-message-card:#fff;--ticket-message-staff:#f3fbfc;--ticket-message-own:#eaf8f9;--ticket-message-text:#344054;--ticket-message-staff-text:#25434a;--ticket-message-divider:#edf0f4;--ticket-message-border:#e1e7ee;--ticket-message-staff-border:#cfe7ea;--ticket-message-shadow:#1720330a;color:var(--ticket-ink)}
    .ticket-detail-page *{box-sizing:border-box}
    .ticket-ajax-toast{position:fixed;top:1rem;right:1rem;z-index:1000;max-width:min(420px,calc(100vw - 2rem));padding:.8rem 1rem;border:1px solid #bbf7d0;border-radius:10px;background:#f0fdf4;color:#166534;box-shadow:0 12px 28px #0f172a20;font-size:.82rem;font-weight:700}.ticket-ajax-toast.is-error{border-color:#fecaca;background:#fef2f2;color:#b91c1c}
    .ticket-detail-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:1.25rem;margin:0 0 1.25rem;padding:1.6rem;border:1px solid var(--ticket-line);border-radius:18px;background:linear-gradient(135deg,#fff 0%,#f6fbff 100%);box-shadow:0 8px 28px #17203308}
    .ticket-detail-heading-main{min-width:0}.ticket-detail-eyebrow{display:flex;align-items:center;gap:.55rem;margin-bottom:.55rem;color:#087e8b;font-size:.7rem;font-weight:800;letter-spacing:.11em;text-transform:uppercase}.ticket-detail-eyebrow span{padding:.25rem .5rem;border:1px solid #dbe7ed;border-radius:6px;background:#fff;color:#526273;letter-spacing:.04em}.ticket-detail-heading h1{margin:0;color:var(--ticket-ink);font-size:clamp(1.45rem,2.6vw,2rem);font-weight:750;letter-spacing:-.04em;line-height:1.2;overflow-wrap:anywhere}.ticket-detail-heading p{margin:.6rem 0 0;color:var(--ticket-muted);font-size:.84rem;line-height:1.55}.ticket-detail-heading-actions{display:flex;flex:none;gap:.55rem}.ticket-detail-heading-actions .btn{display:inline-flex;align-items:center;gap:.45rem;border-radius:9px;padding:.65rem .9rem;font-size:.8rem;font-weight:700;text-decoration:none}
    .ticket-detail-disabled{display:flex;align-items:flex-start;gap:.7rem;margin-bottom:1rem;padding:.85rem 1rem;border:1px solid #f3d29b;border-radius:11px;background:#fff9ed;color:#81530d;font-size:.82rem;line-height:1.55}.ticket-detail-disabled strong{color:#744b0b}
    .ticket-detail-facts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.8rem;margin-bottom:1rem}.ticket-fact{display:flex;min-width:0;align-items:center;gap:.8rem;padding:1rem;border:1px solid var(--ticket-line);border-radius:13px;background:var(--ticket-surface);box-shadow:0 4px 16px #17203306}.ticket-fact-icon{display:grid;width:2.35rem;height:2.35rem;flex:none;place-items:center;border-radius:10px;background:#edf8fa;color:#087e8b;font-size:1rem;font-weight:800}.ticket-fact-copy{min-width:0}.ticket-fact-label{display:block;margin-bottom:.18rem;color:var(--ticket-muted);font-size:.67rem;font-weight:750;letter-spacing:.07em;text-transform:uppercase}.ticket-fact-value{display:block;overflow:hidden;color:var(--ticket-ink);font-size:.86rem;font-weight:750;text-overflow:ellipsis;white-space:nowrap}.ticket-fact-value .status-badge{display:inline-flex;margin:0;border:0;border-radius:999px;padding:.26rem .55rem;background:#eaf7f1;color:#17744b;font-size:.7rem;font-weight:750}.ticket-fact-value .ticket-status-in_progress{background:#eef4ff;color:#3459a8}.ticket-fact-value .ticket-status-waiting_customer{background:#fff5e7;color:#9a5a08}.ticket-fact-value .ticket-status-resolved{background:#eaf7f1;color:#17744b}.ticket-fact-value .ticket-status-closed{background:#f0f2f5;color:#5f6877}.ticket-fact-sub{display:block;margin-top:.16rem;color:#8791a1;font-size:.7rem;white-space:nowrap}
    .ticket-detail-layout{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:1rem;align-items:start}.ticket-detail-main,.ticket-detail-sidebar{display:grid;min-width:0;gap:1rem}.ticket-detail-sidebar{position:sticky;top:1rem}.ticket-detail-page .ticket-panel{overflow:hidden;margin:0;border:1px solid var(--ticket-line);border-radius:14px;background:#fff;box-shadow:0 5px 18px #17203307}.ticket-detail-page .ticket-panel-body{padding:1.15rem 1.25rem}.ticket-panel-heading{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.95rem 1.2rem;border-bottom:1px solid var(--ticket-line);background:#fff}.ticket-panel-heading h2{margin:0;color:var(--ticket-ink);font-size:.91rem;font-weight:800;letter-spacing:-.015em}.ticket-panel-heading p{margin:.22rem 0 0;color:var(--ticket-muted);font-size:.74rem;line-height:1.45}.ticket-description{color:#344054;font-size:.88rem;line-height:1.75;overflow-wrap:anywhere}.ticket-description-text{white-space:pre-wrap}.ticket-attachment-list{display:grid;gap:.5rem;margin:1rem 0 0;padding:0;list-style:none}.ticket-attachment-item{display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;padding:.7rem;border:1px solid var(--ticket-line);border-radius:9px;background:#fafbfd}.ticket-attachment-name{display:flex;min-width:0;align-items:center;gap:.55rem;color:#344054;font-size:.78rem;font-weight:650;overflow-wrap:anywhere}.ticket-attachment-meta{display:block;margin-top:.14rem;color:#8992a0;font-size:.68rem;font-weight:500}.ticket-attachment-actions{display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-end;gap:.4rem}.ticket-attachment-actions>a{color:#087e8b;font-size:.75rem;font-weight:750;text-decoration:none}.ticket-moderation-form{display:flex;flex-wrap:wrap;align-items:center;gap:.4rem}.ticket-moderation-form .form-input{width:auto;min-width:115px;min-height:34px;padding:.4rem .55rem;font-size:.72rem}.ticket-moderation-form .btn{min-height:34px;padding:.38rem .6rem;font-size:.71rem}
    .ticket-sidebar-section{padding:1.05rem 1.15rem}.ticket-sidebar-section+.ticket-sidebar-section{border-top:1px solid var(--ticket-line)}.ticket-sidebar-label{display:block;margin-bottom:.45rem;color:#7a8493;font-size:.66rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.ticket-sidebar-value{color:var(--ticket-ink);font-size:.86rem;font-weight:750;overflow-wrap:anywhere}.ticket-sidebar-sub{margin-top:.25rem;color:var(--ticket-muted);font-size:.75rem;line-height:1.5;overflow-wrap:anywhere}.ticket-sla-state{display:inline-flex;align-items:center;gap:.4rem}.ticket-sla-dot{width:.48rem;height:.48rem;border-radius:50%;background:#20a56b}.ticket-sla-approaching .ticket-sla-dot{background:#e99a23}.ticket-sla-breached .ticket-sla-dot{background:#d14343}.ticket-sla-paused .ticket-sla-dot,.ticket-sla-stopped .ticket-sla-dot{background:#8590a1}.ticket-sla-remaining{margin-top:.55rem;color:var(--ticket-ink);font-size:1.15rem;font-weight:800;letter-spacing:-.03em}.ticket-sidebar-section .page-description{margin:.45rem 0 0;color:var(--ticket-muted);font-size:.73rem;line-height:1.5}.ticket-customer-avatar{display:grid;width:2.3rem;height:2.3rem;flex:none;place-items:center;border-radius:50%;background:#e8f2ff;color:#3459a8;font-size:.78rem;font-weight:800}.ticket-customer-row{display:flex;align-items:center;gap:.7rem}.ticket-customer-row p{margin:0}.ticket-customer-row .ticket-sidebar-sub{margin-top:.12rem}
    .ticket-actions-panel .ticket-panel-heading{background:#fbfcfe}.ticket-action-group{display:grid;gap:.65rem;padding:1rem 1.15rem;border-bottom:1px solid #edf0f4}.ticket-action-group:last-child{border-bottom:0}.ticket-action-group h3{margin:0;color:#344054;font-size:.78rem;font-weight:800}.ticket-action-group>p{margin:0;color:var(--ticket-muted);font-size:.72rem;line-height:1.5}.ticket-action-group .form-label{margin:0;color:#475467;font-size:.72rem;font-weight:700}.ticket-action-group .form-input{width:100%;min-width:0;min-height:38px;border-color:#d9dfe8;border-radius:8px;background:#fff;font-size:.78rem}.ticket-action-group textarea.form-input{min-height:82px;resize:vertical}.ticket-action-group .btn{justify-content:center;min-height:37px;border-radius:8px;font-size:.75rem;font-weight:750}.ticket-action-group .form-error{margin:-.25rem 0 0;font-size:.72rem}.ticket-transition-form{display:grid;gap:.55rem;padding:.75rem 0;border-top:1px solid #edf0f4}.ticket-transition-form:first-of-type{padding-top:0;border-top:0}.ticket-transition-form button{width:100%}.ticket-detail-page .ticket-conversation{margin:0}.ticket-conversation-empty{padding:1.5rem;text-align:center;color:var(--ticket-muted);font-size:.8rem}.ticket-back-link{display:inline-flex;align-items:center;gap:.4rem;margin-top:1rem;color:#64748b;font-size:.78rem;font-weight:700;text-decoration:none}.ticket-back-link:hover{color:#087e8b}
    .ticket-conversation-body{padding:0 1.2rem}
    .ticket-message{display:grid;grid-template-columns:2.35rem minmax(0,1fr);gap:.8rem;padding:1.1rem 0;border-bottom:1px solid #edf0f4}
    .ticket-message:last-child{border-bottom:0}
    .ticket-message-internal{margin:0 -.55rem;padding:.95rem .55rem;border-radius:9px;background:#fffbf3}
    .ticket-message-avatar{display:grid;width:2.25rem;height:2.25rem;place-items:center;border:1px solid #dbe7ed;border-radius:50%;background:#edf8fa;color:#087e8b;font-size:.75rem;font-weight:800}
    .ticket-message-internal .ticket-message-avatar{border-color:#f1dfb8;background:#fff2d8;color:#93600d}
    .ticket-message-content{min-width:0}
    .ticket-message-header{display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem}
    .ticket-message-author{display:grid;gap:.18rem;min-width:0}.ticket-message-author strong{color:var(--ticket-ink);font-size:.8rem;font-weight:800}.ticket-message-author span{color:#8992a0;font-size:.68rem}
    .ticket-message-tags{display:flex;flex:none;flex-wrap:wrap;justify-content:flex-end;gap:.3rem}
    .ticket-message-tag{display:inline-flex;align-items:center;border-radius:999px;padding:.22rem .5rem;font-size:.62rem;font-weight:750}
    .ticket-message-tag-public{background:#eaf7f1;color:#17744b}.ticket-message-tag-internal{background:#fff0cf;color:#89590b}.ticket-message-tag-muted{background:#f0f2f5;color:#687384}
    .ticket-message-body{margin-top:.65rem;color:#344054;font-size:.83rem;line-height:1.7;white-space:pre-wrap;overflow-wrap:anywhere}
    .ticket-message-removed{margin:.65rem 0 0;color:#7b8492;font-size:.78rem;font-style:italic}
    .ticket-message-attachment{display:flex;align-items:center;flex-wrap:wrap;gap:.65rem;margin-top:.7rem;padding:.6rem .7rem;border:1px solid var(--ticket-line);border-radius:8px;background:#fff}
    .ticket-message-attachment>span:first-child{display:grid;min-width:100px;flex:1;gap:.15rem;color:#344054;font-size:.72rem;overflow-wrap:anywhere}.ticket-message-attachment small{color:#8992a0;font-size:.65rem}.ticket-message-attachment>a{color:#087e8b;font-size:.72rem;font-weight:750;text-decoration:none}.ticket-message-attachment-unavailable{color:#8992a0;font-size:.7rem}
    .ticket-message-moderation{margin-top:.7rem;padding-top:.7rem;border-top:1px dashed #e1e5eb}
    .ticket-conversation-empty{display:grid;justify-items:center;gap:.4rem;padding:2.5rem 1.2rem;text-align:center;color:var(--ticket-muted)}.ticket-conversation-empty>span{display:grid;width:2.5rem;height:2.5rem;place-items:center;border-radius:50%;background:#f1f5f9;color:#94a3b8;font-size:1.25rem}.ticket-conversation-empty strong{color:#344054;font-size:.82rem}.ticket-conversation-empty p{margin:0;font-size:.74rem}
    .ticket-message-count{display:inline-flex;align-items:center;border:1px solid #e3e8ef;border-radius:999px;padding:.28rem .58rem;background:#f8fafc;color:#667085;font-size:.68rem;font-weight:750;white-space:nowrap}
    .ticket-composer-note{margin:0 0 .8rem;padding:.7rem .8rem;border:1px solid #d8e9f2;border-radius:8px;background:#f1f9fc;color:#3f6170;font-size:.73rem;line-height:1.5}
    .ticket-composer-form{display:grid;gap:.55rem}.ticket-composer-form .form-label{margin:0;color:#475467;font-size:.75rem;font-weight:750}.ticket-composer-form .form-input{width:100%;min-width:0;border-color:#d9dfe8;border-radius:9px;font-size:.79rem}.ticket-composer-form textarea.form-input{resize:vertical}.ticket-composer-form .form-error{margin:0;font-size:.72rem}
    .ticket-upload-hint{margin:-.3rem 0 0;color:#8992a0;font-size:.68rem}.ticket-optional{margin-left:.3rem;color:#98a2b3;font-size:.67rem;font-weight:500}
    .ticket-composer-footer{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-top:.35rem;padding-top:.75rem;border-top:1px solid #edf0f4}.ticket-composer-footer .btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border-radius:8px;padding:.55rem .9rem;font-size:.75rem;font-weight:750}.ticket-composer-private{display:inline-flex;align-items:center;gap:.4rem;color:#667085;font-size:.69rem;font-weight:650}.ticket-composer-private span{color:#087e8b}
    .ticket-internal-composer .ticket-panel-heading{background:#fffdf8}.ticket-composer-icon{display:grid;width:1.9rem;height:1.9rem;flex:none;place-items:center;border-radius:8px;background:#edf8fa;color:#087e8b;font-size:1rem;font-weight:800}.ticket-internal-composer .ticket-composer-icon{background:#fff2d8;color:#93600d}.ticket-reopen-check{display:flex;align-items:center;gap:.45rem;color:#475467;font-size:.73rem}
    .ticket-conversation-summary{display:flex;align-items:center;flex-wrap:wrap;justify-content:flex-end;gap:.4rem}.ticket-internal-count,.ticket-internal-empty-label{display:inline-flex;align-items:center;border-radius:999px;padding:.28rem .58rem;background:#fff7e8;color:#94620f;font-size:.66rem;font-weight:750;white-space:nowrap}.ticket-internal-empty-label{background:#f4f6f8;color:#7b8492}
    .ticket-message-public{padding:.8rem 0;border:0}.ticket-message-from-customer{grid-template-columns:2.35rem minmax(0,1fr)}.ticket-message-from-staff{grid-template-columns:minmax(0,1fr) 2.35rem}.ticket-message-from-staff .ticket-message-avatar{grid-column:2;grid-row:1}.ticket-message-from-staff .ticket-message-content{grid-column:1;grid-row:1;justify-self:end;width:min(100%,720px)}.ticket-message-from-staff .ticket-message-header{flex-direction:row-reverse}.ticket-message-from-staff .ticket-message-author{text-align:right}.ticket-message-from-staff .ticket-message-tags{justify-content:flex-start}.ticket-message-public .ticket-message-body{width:fit-content;max-width:min(100%,720px);padding:.75rem .9rem;border:1px solid #e7ebf0;border-radius:5px 14px 14px 14px;background:#f5f7fa}.ticket-message-from-staff .ticket-message-body{margin-left:auto;border-color:#d8edf0;border-radius:14px 5px 14px 14px;background:#edf8f9}.ticket-message-public .ticket-message-removed{width:fit-content;max-width:100%;padding:.65rem .8rem;border-radius:10px;background:#f5f7fa}
    .ticket-message-content{display:flex;flex-direction:column;align-items:flex-start}.ticket-message-from-staff .ticket-message-content{align-items:flex-end}.ticket-message-from-staff .ticket-message-header{align-items:flex-end;flex-direction:column;gap:.32rem}.ticket-message-from-staff .ticket-message-author{text-align:right}.ticket-message-from-staff .ticket-message-tags{justify-content:flex-end}.ticket-message-public .ticket-message-body{max-width:100%;margin-top:.4rem}.ticket-message-attachment{width:fit-content;max-width:100%;align-self:flex-start}.ticket-message-from-staff .ticket-message-attachment{align-self:flex-end}.ticket-message-moderation{width:max-content;max-width:100%;align-self:flex-start;justify-content:flex-start;margin-top:.45rem;padding:.4rem 0 0;border-top:1px solid #e7ebf0}.ticket-message-from-staff .ticket-message-moderation{align-self:flex-end;justify-content:flex-end}.ticket-message-moderation .form-input{max-width:100%}.ticket-message-own{margin-inline:-.55rem;padding:.65rem .55rem;border:1px solid #cce9ec;border-radius:13px;background:#f3fbfc}.ticket-message-own .ticket-message-body{box-shadow:0 3px 10px #0e74900b}
    .ticket-internal-note-row{display:flex;align-items:center;gap:.75rem;margin:.45rem 0;padding:.65rem .75rem;border:1px solid #f0dfbb;border-left:3px solid #d39a2f;border-radius:10px;background:#fffaf0}.ticket-internal-note-icon{display:grid;width:1.9rem;height:1.9rem;flex:none;place-items:center;border-radius:8px;background:#fff0cf;color:#92600e}.ticket-internal-note-copy{min-width:0;flex:1}.ticket-internal-note-meta{display:flex;align-items:center;flex-wrap:wrap;gap:.4rem}.ticket-internal-note-meta strong{color:#5e4a27;font-size:.72rem;font-weight:800}.ticket-internal-note-meta>span:not(.ticket-message-tag){color:#9a8b72;font-size:.65rem}.ticket-internal-note-copy p{overflow:hidden;margin:.18rem 0 0;color:#75674f;font-size:.72rem;line-height:1.4;text-overflow:ellipsis;white-space:nowrap}.ticket-internal-note-open{display:inline-flex;flex:none;align-items:center;gap:.35rem;border:1px solid #ead8b0;border-radius:7px;padding:.42rem .58rem;background:#fff;color:#80580e;font:inherit;font-size:.68rem;font-weight:750;cursor:pointer}.ticket-internal-note-open:hover{border-color:#d3a343;background:#fff5df}.ticket-internal-note-open:focus-visible,.ticket-note-dialog-close:focus-visible{outline:3px solid #f0bd58;outline-offset:2px}
    .ticket-internal-composer{overflow:hidden}.ticket-internal-composer-summary{display:flex;align-items:center;gap:.7rem;padding:.8rem 1.1rem;cursor:pointer;list-style:none}.ticket-internal-composer-summary::-webkit-details-marker{display:none}.ticket-internal-composer-summary>span:nth-child(2){display:grid;flex:1;gap:.12rem}.ticket-internal-composer-summary strong{color:#344054;font-size:.78rem;font-weight:800}.ticket-internal-composer-summary small{color:#8992a0;font-size:.67rem}.ticket-internal-composer-chevron{color:#7d8796;transition:transform .15s ease}.ticket-internal-composer[open] .ticket-internal-composer-chevron{transform:rotate(180deg)}.ticket-internal-composer[open] .ticket-internal-composer-summary{border-bottom:1px solid var(--ticket-line);background:#fffdf8}
    .ticket-issue-accordion{overflow:hidden}.ticket-issue-summary{display:flex;min-height:68px;align-items:center;gap:.75rem;padding:.85rem 1.1rem;cursor:pointer;list-style:none}.ticket-issue-summary::-webkit-details-marker{display:none}.ticket-issue-summary:focus-visible{outline:3px solid #35aab6;outline-offset:-3px}.ticket-issue-icon{display:grid;width:2rem;height:2rem;flex:none;place-items:center;border-radius:9px;background:#edf8fa;color:#087e8b;font-size:1.1rem;font-weight:800}.ticket-issue-summary-copy{display:grid;min-width:0;flex:1;gap:.16rem}.ticket-issue-summary-copy strong{color:#344054;font-size:.8rem;font-weight:800}.ticket-issue-summary-copy small{color:#8992a0;font-size:.68rem}.ticket-issue-attachment-count{border:1px solid #e3e8ef;border-radius:999px;padding:.28rem .55rem;color:#667085;font-size:.65rem;font-weight:700;white-space:nowrap}.ticket-issue-chevron{color:#7d8796;transition:transform .15s ease}.ticket-issue-accordion[open] .ticket-issue-chevron{transform:rotate(180deg)}.ticket-issue-accordion[open] .ticket-issue-summary{border-bottom:1px solid var(--ticket-line);background:#f9fbfd}
    .ticket-chat-workspace{display:grid;gap:0}.ticket-chat-workspace>.ticket-conversation{border-bottom-right-radius:0!important;border-bottom-left-radius:0!important}.ticket-chat-workspace>.ticket-chat-reply{margin-top:-1px!important;border-top:1px solid var(--ticket-line)!important;border-top-left-radius:0!important;border-top-right-radius:0!important}.ticket-chat-reply .ticket-panel-heading{background:#f9fbfd}.ticket-chat-reply .ticket-panel-body{padding-top:1rem}.ticket-chat-reply .ticket-chat-textarea{min-height:96px;border-radius:12px!important;padding:.85rem .95rem;background:#fbfcfe;line-height:1.6}.ticket-chat-reply .ticket-chat-textarea::placeholder{color:#98a2b3}.ticket-public-label{display:inline-flex;align-items:center;gap:.35rem;border-radius:999px;padding:.35rem .6rem;background:#eaf7f1;color:#17744b;font-size:.65rem;font-weight:800;white-space:nowrap}.ticket-public-label span{font-size:.55rem}.ticket-chat-attachment-row{display:flex;align-items:center;gap:.65rem;flex-wrap:wrap}.ticket-chat-attachment-label{display:inline-flex;align-items:center;gap:.35rem;color:#087e8b;font-size:.72rem;font-weight:750;cursor:pointer}.ticket-chat-attachment-label span{font-size:1rem}.ticket-chat-file-input{max-width:min(100%,390px);padding:.32rem!important;font-size:.68rem!important}.ticket-chat-reply .ticket-composer-footer{margin-top:.15rem}.ticket-chat-reply .ticket-composer-footer .btn{min-width:125px;gap:.4rem}
    .ticket-note-dialog{width:min(620px,calc(100vw - 2rem));max-width:none;max-height:min(86vh,850px);margin:auto;padding:0;overflow:hidden;border:1px solid #dce2e9;border-radius:16px;background:#fff;color:#172033;box-shadow:0 24px 80px #0f172a40}.ticket-note-dialog::backdrop{background:#11182799;backdrop-filter:blur(3px)}.ticket-note-dialog-panel{display:flex;max-height:min(86vh,850px);flex-direction:column}.ticket-note-dialog-header{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;padding:1.15rem 1.25rem;border-bottom:1px solid #e6eaf0;background:#fffaf0}.ticket-note-dialog-kicker{display:block;margin-bottom:.3rem;color:#92600e;font-size:.64rem;font-weight:850;letter-spacing:.09em;text-transform:uppercase}.ticket-note-dialog-header h2{margin:0;color:#172033;font-size:1rem;font-weight:800;letter-spacing:-.02em}.ticket-note-dialog-header p{margin:.28rem 0 0;color:#7b8492;font-size:.7rem}.ticket-note-dialog-close{display:grid;width:2rem;height:2rem;flex:none;place-items:center;border:1px solid #e5dcc8;border-radius:8px;background:#fff;color:#626b78;font-size:1.35rem;line-height:1;cursor:pointer}.ticket-note-dialog-body{overflow:auto;padding:1.2rem 1.25rem}.ticket-note-full-text{color:#344054;font-size:.84rem;line-height:1.75;white-space:pre-wrap;overflow-wrap:anywhere}.ticket-note-state{margin-top:.85rem;padding:.55rem .7rem;border-radius:7px;background:#f2f4f7;color:#667085;font-size:.7rem}.ticket-note-files{margin-top:1.15rem}.ticket-note-files h3{margin:0 0 .55rem;color:#475467;font-size:.74rem;font-weight:800}.ticket-note-dialog-body>.ticket-message-moderation{margin-top:1rem}
    @media(max-width:1120px){.ticket-detail-layout{grid-template-columns:minmax(0,1fr) 310px}.ticket-detail-facts{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:880px){.ticket-detail-layout{grid-template-columns:1fr}.ticket-detail-sidebar{position:static;grid-row:auto}.ticket-detail-main{grid-row:auto}}
    @media(max-width:600px){.ticket-detail-heading{flex-direction:column;padding:1.1rem}.ticket-detail-heading-actions{width:100%}.ticket-detail-heading-actions .btn{justify-content:center;flex:1}.ticket-detail-facts{grid-template-columns:repeat(2,minmax(0,1fr));gap:.55rem}.ticket-fact{gap:.55rem;padding:.75rem}.ticket-fact-icon{width:2rem;height:2rem;border-radius:8px}.ticket-fact-value{font-size:.77rem}.ticket-panel-heading{padding:.85rem 1rem}.ticket-detail-page .ticket-panel-body{padding:1rem}.ticket-sidebar-section,.ticket-action-group{padding:.9rem 1rem}.ticket-attachment-item{align-items:flex-start;flex-direction:column}.ticket-attachment-actions{justify-content:flex-start;width:100%}.ticket-conversation-body{padding:0 .8rem}.ticket-message{grid-template-columns:1.9rem minmax(0,1fr);gap:.6rem}.ticket-message-from-staff{grid-template-columns:minmax(0,1fr) 1.9rem}.ticket-message-avatar{width:1.85rem;height:1.85rem}.ticket-message-header{flex-direction:column}.ticket-message-from-staff .ticket-message-header{align-items:flex-end;flex-direction:column}.ticket-message-tags{justify-content:flex-start}.ticket-message-from-staff .ticket-message-tags{justify-content:flex-end}.ticket-message-public .ticket-message-body{max-width:100%}.ticket-conversation-summary{align-items:flex-end;flex-direction:column}.ticket-internal-note-row{gap:.5rem;padding:.55rem}.ticket-internal-note-icon{width:1.65rem;height:1.65rem}.ticket-internal-note-open{padding:.4rem .45rem;font-size:.64rem}.ticket-internal-note-meta>span:not(.ticket-message-tag){font-size:.61rem}.ticket-composer-footer{align-items:flex-start;flex-direction:column}.ticket-composer-footer .btn{width:100%}.ticket-note-dialog-header,.ticket-note-dialog-body{padding:1rem}}
    html.dark .ticket-detail-page{--ticket-ink:#e7edf6;--ticket-muted:#a4afc0;--ticket-line:#344154;--ticket-surface:#1b2534;--ticket-soft:#151e2b;color:var(--ticket-ink);color-scheme:dark}
    html.dark .ticket-detail-heading{border-color:var(--ticket-line);background:linear-gradient(135deg,#1b2534 0%,#1b2a39 100%);box-shadow:0 8px 28px #00000020}
    html.dark .ticket-detail-heading h1,html.dark .ticket-fact-value,html.dark .ticket-sidebar-value,html.dark .ticket-panel-heading h2,html.dark .ticket-customer-row .ticket-sidebar-value{color:var(--ticket-ink)}
    html.dark .ticket-detail-eyebrow{color:#67d5dd}html.dark .ticket-detail-eyebrow span{border-color:#3a4a5e;background:#202d3e;color:#c2ccda}
    html.dark .ticket-detail-disabled{border-color:#705b35;background:#332b1d;color:#f4d38d}html.dark .ticket-detail-disabled strong{color:#ffe2a5}
    html.dark .ticket-fact,html.dark .ticket-detail-page .ticket-panel{border-color:var(--ticket-line);background:var(--ticket-surface);box-shadow:0 5px 18px #00000012}
    html.dark .ticket-fact-icon{background:#203743;color:#72d5dc}html.dark .ticket-fact-label,html.dark .ticket-fact-sub,html.dark .ticket-panel-heading p,html.dark .ticket-sidebar-sub,html.dark .ticket-detail-heading p{color:var(--ticket-muted)}
    html.dark .ticket-panel-heading,html.dark .ticket-actions-panel .ticket-panel-heading{border-color:var(--ticket-line);background:#1d2939}
    html.dark .ticket-description,html.dark .ticket-message-body,html.dark .ticket-attachment-name,html.dark .ticket-message-author strong{color:#dbe4ef}
    html.dark .ticket-attachment-item,html.dark .ticket-message-attachment{border-color:var(--ticket-line);background:#172231}
    html.dark .ticket-moderation-form .form-input,html.dark .ticket-action-group .form-input,html.dark .ticket-composer-form .form-input{border-color:#435169;background:#131c29;color:#e2e8f0;color-scheme:dark}
    html.dark .ticket-moderation-form .form-input:focus,html.dark .ticket-action-group .form-input:focus,html.dark .ticket-composer-form .form-input:focus{border-color:#55c5d0;box-shadow:0 0 0 3px #55c5d022;outline:0}
    html.dark .ticket-sidebar-section+.ticket-sidebar-section,html.dark .ticket-action-group,html.dark .ticket-transition-form,html.dark .ticket-composer-footer,html.dark .ticket-message{border-color:#344154}
    html.dark .ticket-actions-panel .ticket-panel-heading,html.dark .ticket-internal-composer .ticket-panel-heading{background:#202b3a}
    html.dark .ticket-action-group h3,html.dark .ticket-action-group .form-label,html.dark .ticket-composer-form .form-label,html.dark .ticket-reopen-check{color:#d4dce7}
    html.dark .ticket-message-internal{background:#302a20}html.dark .ticket-message-internal .ticket-message-avatar{border-color:#705b35;background:#49391f;color:#f4d38d}
    html.dark .ticket-message-avatar{border-color:#3a5964;background:#203743;color:#72d5dc}
    html.dark .ticket-message-tag-public{background:#173b32;color:#8be0b7}html.dark .ticket-message-tag-internal{background:#49391f;color:#f4d38d}html.dark .ticket-message-tag-muted{background:#303b4a;color:#c0cad8}
    html.dark .ticket-message-count{border-color:#3a4658;background:#202c3b;color:#c0cad8}
    html.dark .ticket-composer-note{border-color:#315766;background:#1b3340;color:#b9e4e9}
    html.dark .ticket-internal-composer .ticket-composer-icon{background:#49391f;color:#f4d38d}html.dark .ticket-composer-icon{background:#203743;color:#72d5dc}
    html.dark .ticket-conversation-empty>span{background:#263344;color:#a4afc0}html.dark .ticket-conversation-empty strong{color:#dbe4ef}
    html.dark .ticket-customer-avatar{background:#263954;color:#a9c6ff}html.dark .ticket-back-link{color:#a4afc0}html.dark .ticket-back-link:hover,html.dark .ticket-attachment-actions>a,html.dark .ticket-message-attachment>a{color:#72d5dc}
    html.dark .ticket-fact-value .ticket-status-open{background:#173b32;color:#8be0b7}html.dark .ticket-fact-value .ticket-status-in_progress{background:#243554;color:#b2c7ff}html.dark .ticket-fact-value .ticket-status-waiting_customer{background:#49391f;color:#f4d38d}html.dark .ticket-fact-value .ticket-status-resolved{background:#173b32;color:#8be0b7}html.dark .ticket-fact-value .ticket-status-closed{background:#303b4a;color:#c0cad8}
    html.dark .ticket-sla-approaching .ticket-sla-dot{background:#f0b44d}html.dark .ticket-sla-breached .ticket-sla-dot{background:#f07171}html.dark .ticket-sla-paused .ticket-sla-dot,html.dark .ticket-sla-stopped .ticket-sla-dot{background:#8998ac}
    html.dark .ticket-internal-count{background:#49391f;color:#f4d38d}html.dark .ticket-internal-empty-label{background:#303b4a;color:#c0cad8}
    html.dark .ticket-message-from-customer .ticket-message-body{border-color:#3a4658;background:#242f3e;color:#dbe4ef}html.dark .ticket-message-from-staff .ticket-message-body{border-color:#315766;background:#1b3340;color:#d4edf0}html.dark .ticket-message-public .ticket-message-removed{background:#242f3e;color:#b7c1cf}
    html.dark .ticket-message-moderation{border-color:#344154}html.dark .ticket-message-own{border-color:#315766;background:#172a34}html.dark .ticket-ajax-toast{border-color:#285447;background:#173b32;color:#8be0b7}html.dark .ticket-ajax-toast.is-error{border-color:#694141;background:#3a2525;color:#ffaaaa}
    html.dark .ticket-internal-note-row{border-color:#5d4b2c;border-left-color:#d3a343;background:#302a20}html.dark .ticket-internal-note-icon{background:#49391f;color:#f4d38d}html.dark .ticket-internal-note-meta strong{color:#f0dba9}html.dark .ticket-internal-note-meta>span:not(.ticket-message-tag){color:#b9a983}html.dark .ticket-internal-note-copy p{color:#d3c4a2}html.dark .ticket-internal-note-open{border-color:#705b35;background:#3b301f;color:#f4d38d}html.dark .ticket-internal-note-open:hover{background:#49391f}
    html.dark .ticket-internal-composer-summary strong{color:#e7edf6}html.dark .ticket-internal-composer-summary small,html.dark .ticket-internal-composer-chevron{color:#a4afc0}html.dark .ticket-internal-composer[open] .ticket-internal-composer-summary{border-color:var(--ticket-line);background:#202b3a}
    html.dark .ticket-note-dialog{border-color:#435169;background:#1b2534;color:#e7edf6}html.dark .ticket-note-dialog-header{border-color:#435169;background:#302a20}html.dark .ticket-note-dialog-header h2{color:#e7edf6}html.dark .ticket-note-dialog-header p{color:#a4afc0}html.dark .ticket-note-dialog-kicker{color:#f4d38d}html.dark .ticket-note-dialog-close{border-color:#5d4b2c;background:#3b301f;color:#e7edf6}html.dark .ticket-note-full-text{color:#dbe4ef}html.dark .ticket-note-state{background:#303b4a;color:#c0cad8}html.dark .ticket-note-files h3{color:#d4dce7}
    html.dark .ticket-issue-summary-copy strong{color:#e7edf6}html.dark .ticket-issue-summary-copy small,html.dark .ticket-issue-chevron{color:#a4afc0}html.dark .ticket-issue-attachment-count{border-color:#3a4658;background:#202c3b;color:#c0cad8}html.dark .ticket-issue-accordion[open] .ticket-issue-summary,html.dark .ticket-chat-reply .ticket-panel-heading{border-color:var(--ticket-line);background:#202b3a}html.dark .ticket-issue-icon{background:#203743;color:#72d5dc}html.dark .ticket-chat-reply .ticket-chat-textarea{background:#131c29}html.dark .ticket-chat-textarea::placeholder{color:#8491a3}html.dark .ticket-public-label{background:#173b32;color:#8be0b7}
    .ticket-conversation-body{padding:0 1.15rem}
    .ticket-message{align-items:start;gap:.75rem;padding:.9rem 0;border-bottom:1px solid #e8edf3}
    .ticket-message-content{width:100%;min-width:0;max-width:720px;align-items:flex-start}
    .ticket-message-header{width:100%;align-items:flex-start;justify-content:space-between;gap:.65rem}
    .ticket-message-from-staff .ticket-message-content{width:min(100%,720px);max-width:720px;align-items:flex-end}
    .ticket-message-from-staff .ticket-message-header{width:100%;flex-direction:row;align-items:flex-start;justify-content:flex-end;gap:.55rem}
    .ticket-message-from-staff .ticket-message-author{min-width:0;text-align:right}
    .ticket-message-from-staff .ticket-message-tags{justify-content:flex-end}
    .ticket-message-public .ticket-message-body{max-width:min(100%,680px);margin:.4rem 0 0;align-self:flex-start}
    .ticket-message-from-staff .ticket-message-body{align-self:flex-end;margin-left:0}
    .ticket-message-attachment{max-width:min(100%,680px);align-self:flex-start;margin-top:.45rem}
    .ticket-message-from-staff .ticket-message-attachment{align-self:flex-end}
    .ticket-message-moderation{display:flex;width:fit-content;max-width:100%;align-self:flex-start;gap:.4rem;margin:.45rem 0 0;padding:0;border:0}
    .ticket-message-from-staff .ticket-message-moderation{align-self:flex-end;justify-content:flex-end}
    .ticket-message-own{margin:0;padding:.7rem;border-color:#cce9ec;border-radius:12px;background:#f3fbfc}
    .ticket-message-own .ticket-message-body{box-shadow:none}
    @media(max-width:600px){.ticket-conversation-body{padding:0 .75rem}.ticket-message{grid-template-columns:1.9rem minmax(0,1fr);gap:.55rem;padding:.8rem 0}.ticket-message-from-staff{grid-template-columns:minmax(0,1fr) 1.9rem}.ticket-message-avatar{width:1.8rem;height:1.8rem}.ticket-message-content,.ticket-message-from-staff .ticket-message-content{max-width:100%}.ticket-message-header,.ticket-message-from-staff .ticket-message-header{flex-wrap:wrap;gap:.35rem}.ticket-message-from-staff .ticket-message-header{justify-content:flex-end}.ticket-message-author span{line-height:1.4}.ticket-message-moderation{flex-wrap:wrap}.ticket-message-moderation .form-input{min-width:0;max-width:100%}.ticket-message-own{padding:.55rem}}
    html.dark .ticket-message-own{border-color:#315766;background:#172a34}
    .ticket-detail-page .ticket-conversation-body{display:grid;gap:.15rem;padding:1rem 1.1rem}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public{display:grid;grid-template-columns:2.15rem minmax(0,1fr);align-items:start;gap:.65rem;margin:0;padding:.7rem 0;border-bottom:1px solid #e8edf3}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public:last-child{border-bottom:0}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-avatar{width:2.05rem;height:2.05rem;margin-top:.45rem}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-content{display:flex;width:min(100%,680px);min-width:0;max-width:84%;flex-direction:column;align-items:stretch;justify-self:start;padding:.8rem .9rem;border:1px solid #e2e8f0;border-radius:14px;background:#f8fafc;box-shadow:0 3px 10px #0f172a08}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-header{display:flex;width:100%;align-items:flex-start;justify-content:space-between;gap:.7rem}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-author{min-width:0;text-align:left}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-tags{flex:none;justify-content:flex-end}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-body{width:100%;max-width:none;align-self:stretch;margin:.5rem 0 0;padding:0;border:0;border-radius:0;background:transparent;box-shadow:none;color:#344054;font-size:.84rem;line-height:1.65}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-removed{padding:.55rem .65rem;border-radius:8px;background:#f1f5f9;color:#687384}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-attachment{width:100%;max-width:none;align-self:stretch;margin-top:.55rem}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-moderation{display:flex;width:100%;max-width:none;align-self:stretch;justify-content:flex-end;gap:.4rem;margin:.65rem 0 0;padding:.6rem 0 0;border-top:1px solid #e2e8f0}
    .ticket-detail-page .ticket-conversation-body .ticket-message-from-staff{grid-template-columns:minmax(0,1fr) 2.15rem}
    .ticket-detail-page .ticket-conversation-body .ticket-message-from-staff .ticket-message-avatar{grid-column:2;grid-row:1}
    .ticket-detail-page .ticket-conversation-body .ticket-message-from-staff .ticket-message-content{grid-column:1;grid-row:1;justify-self:end;background:#effafb;border-color:#cde8eb}
    .ticket-detail-page .ticket-conversation-body .ticket-message-from-staff .ticket-message-header{flex-direction:row;justify-content:space-between}
    .ticket-detail-page .ticket-conversation-body .ticket-message-from-staff .ticket-message-author{text-align:left}
    .ticket-detail-page .ticket-conversation-body .ticket-message-from-staff .ticket-message-body{align-self:stretch;margin-left:0;border-color:transparent;background:transparent;color:#25434a}
    .ticket-detail-page .ticket-conversation-body .ticket-message-from-staff .ticket-message-attachment{align-self:stretch}
    .ticket-detail-page .ticket-conversation-body .ticket-message-own .ticket-message-content{border-color:#79c6ce;background:#e9f8fa;box-shadow:0 4px 14px #0e749012}
    .ticket-detail-page .ticket-conversation-body .ticket-message-own .ticket-message-avatar{border-color:#79c6ce;background:#dff3f5;color:#087e8b}
    .ticket-detail-page .ticket-conversation-body .ticket-message-own .ticket-message-body{color:#163f46}
    @media(max-width:600px){.ticket-detail-page .ticket-conversation-body{padding:.7rem}.ticket-detail-page .ticket-conversation-body .ticket-message-public{grid-template-columns:1.85rem minmax(0,1fr);gap:.5rem;padding:.65rem 0}.ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-avatar{width:1.75rem;height:1.75rem;margin-top:.55rem}.ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-content{max-width:100%;padding:.65rem .7rem}.ticket-detail-page .ticket-conversation-body .ticket-message-from-staff{grid-template-columns:minmax(0,1fr) 1.85rem}.ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-header{flex-wrap:wrap;gap:.35rem}.ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-moderation{justify-content:flex-start}.ticket-detail-page .ticket-conversation-body .ticket-message-from-staff .ticket-message-moderation{justify-content:flex-end}.ticket-detail-page .ticket-conversation-body .ticket-message-moderation .form-input{flex:1 1 120px;min-width:0}}
    html.dark .ticket-detail-page .ticket-conversation-body .ticket-message-public{border-color:#344154}
    html.dark .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-content{border-color:#3a4658;background:#202b39;box-shadow:0 3px 10px #00000018}
    html.dark .ticket-detail-page .ticket-conversation-body .ticket-message-from-staff .ticket-message-content{border-color:#315766;background:#1b3340}
    html.dark .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-body{color:#dbe4ef}
    html.dark .ticket-detail-page .ticket-conversation-body .ticket-message-from-staff .ticket-message-body{color:#d4edf0}
    html.dark .ticket-detail-page .ticket-conversation-body .ticket-message-own .ticket-message-content{border-color:#4c9da5;background:#1b3a41}
    html.dark .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-moderation{border-color:#344154}

    /* Keep each reply, its attachments, and its actions visually grouped as one message card. */
    .ticket-detail-page .ticket-conversation-body{gap:.35rem;padding:1.15rem 1.2rem}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public{grid-template-columns:2.35rem minmax(0,1fr);gap:.75rem;padding:.65rem 0;border:0}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-content{position:relative;width:min(100%,700px);max-width:calc(100% - 2rem);justify-self:start;padding:.9rem 1rem;border:1px solid var(--ticket-message-border);border-radius:15px;background:var(--ticket-message-card);box-shadow:0 5px 16px var(--ticket-message-shadow)}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-header{align-items:center;padding-bottom:.65rem;border-bottom:1px solid var(--ticket-message-divider)}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-author{gap:.22rem}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-author strong{font-size:.82rem;letter-spacing:-.01em}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-author span{font-size:.67rem}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-tag{padding:.24rem .55rem;font-size:.63rem}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-body{margin-top:.72rem;color:var(--ticket-message-text);font-size:.86rem;line-height:1.75;overflow-wrap:anywhere}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-removed{width:100%;margin-top:.7rem}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-attachment{margin-top:.65rem;padding:.65rem .75rem;border-color:var(--ticket-line);border-radius:10px;background:var(--ticket-soft)}
    .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-moderation{margin-top:.7rem;padding-top:.7rem;border-color:var(--ticket-message-divider)}
    .ticket-detail-page .ticket-conversation-body .ticket-message-from-staff{grid-template-columns:minmax(0,1fr) 2.35rem}
    .ticket-detail-page .ticket-conversation-body .ticket-message-from-staff .ticket-message-content{grid-column:1;grid-row:1;justify-self:end;border-color:var(--ticket-message-staff-border);background:var(--ticket-message-staff)}
    .ticket-detail-page .ticket-conversation-body .ticket-message-from-staff .ticket-message-header{flex-direction:row}
    .ticket-detail-page .ticket-conversation-body .ticket-message-from-staff .ticket-message-body{color:var(--ticket-message-staff-text)}
    .ticket-detail-page .ticket-conversation-body .ticket-message-own .ticket-message-content{border-color:#66b8c0;background:var(--ticket-message-own);box-shadow:0 6px 18px #0e749018}
    .ticket-detail-page .ticket-conversation-body .ticket-message-own .ticket-message-header{border-color:var(--ticket-message-staff-border)}
    .ticket-detail-page .ticket-conversation-body .ticket-message-own .ticket-message-avatar{border-color:#66b8c0;background:#d9f0f2;color:#087e8b}
    html.dark .ticket-detail-page{--ticket-message-card:#202b39;--ticket-message-staff:#1b3340;--ticket-message-own:#1b3a41;--ticket-message-text:#dbe4ef;--ticket-message-staff-text:#d4edf0;--ticket-message-divider:#344154;--ticket-message-border:#3a4658;--ticket-message-staff-border:#315766;--ticket-message-shadow:#00000018}
    html.dark .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-header{border-color:var(--ticket-message-divider)}
    html.dark .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-attachment{border-color:#3a4658;background:#172231}
    html.dark .ticket-detail-page .ticket-conversation-body .ticket-message-from-staff .ticket-message-content{border-color:var(--ticket-message-staff-border);background:var(--ticket-message-staff)}
    html.dark .ticket-detail-page .ticket-conversation-body .ticket-message-own .ticket-message-content{border-color:#4c9da5;background:var(--ticket-message-own)}
    @media(max-width:600px){
        .ticket-detail-page .ticket-conversation-body{gap:.2rem;padding:.75rem}
        .ticket-detail-page .ticket-conversation-body .ticket-message-public{grid-template-columns:1.85rem minmax(0,1fr);gap:.5rem;padding:.5rem 0}
        .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-content{max-width:100%;padding:.7rem .75rem;border-radius:12px}
        .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-header{align-items:flex-start;flex-wrap:wrap;gap:.45rem}
        .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-tags{justify-content:flex-start}
        .ticket-detail-page .ticket-conversation-body .ticket-message-from-staff{grid-template-columns:minmax(0,1fr) 1.85rem}
        .ticket-detail-page .ticket-conversation-body .ticket-message-from-staff .ticket-message-header{justify-content:space-between}
        .ticket-detail-page .ticket-conversation-body .ticket-message-from-staff .ticket-message-tags{justify-content:flex-end}
        .ticket-detail-page .ticket-conversation-body .ticket-message-public .ticket-message-moderation{justify-content:flex-start}
        .ticket-detail-page .ticket-conversation-body .ticket-message-from-staff .ticket-message-moderation{justify-content:flex-end}
    }
    .ticket-message.ticket-initial-issue { background:color-mix(in srgb,var(--info) 7%,var(--card)); border:1px solid color-mix(in srgb,var(--info) 25%,var(--border)); border-left:3px solid var(--info); }
    .ticket-initial-issue-title { margin:12px 0 8px; font-size:13px; font-weight:700; color:var(--foreground); }
    .ticket-initial-issue-tag { color:var(--info); background:color-mix(in srgb,var(--info) 12%,var(--card)); }
    html.dark .ticket-message.ticket-initial-issue { background:color-mix(in srgb,var(--info) 9%,var(--card)); border-color:color-mix(in srgb,var(--info) 30%,var(--border)); border-left-color:var(--info); }
    .ticket-agent-facts { grid-template-columns:repeat(3,minmax(0,1fr)); }
    .ticket-detail-heading-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .ticket-detail-heading-actions form { margin:0; }
    .ticket-detail-page .ticket-conversation-body { max-height: min(65vh, 640px); overflow-y:auto; overscroll-behavior:contain; scrollbar-gutter:stable; }
    .ticket-conversation-body:focus-visible { outline:2px solid var(--ring); outline-offset:-3px; }
    @media(max-width:600px) { .ticket-agent-facts { grid-template-columns:1fr; } }
</style>
@endpush
@section('content')
@php($isAdmin = in_array('admin', auth()->user()->tyroRoleSlugs(), true))
@php($statusLabels = ['open'=>'Open','in_progress'=>'In Progress','waiting_customer'=>'Waiting for Customer','resolved'=>'Resolved','closed'=>'Closed'])
@php($slaStateLabels = ['within'=>'On track','approaching'=>'Approaching target','breached'=>'Target breached','paused'=>'Paused','stopped'=>'Stopped'])
@php($remainingSeconds = max(0, (int) $sla['remaining_seconds']))
@php($canStart = collect($transitions)->contains(fn ($transition) => $transition->value === 'start'))
@php($secondaryTransitions = array_values(array_filter($transitions, fn ($transition) => $transition->value !== 'start')))
<div class="ticket-detail-page" data-display-timezone="{{ config('ticketing.display_timezone', 'Asia/Dhaka') }}" data-ticket-status="{{ $ticket->status->value }}" data-customer-id="{{ $ticket->customer_id }}" data-current-user-id="{{ auth()->id() }}">
    <header class="ticket-detail-heading">
        <div class="ticket-detail-heading-main">
            <div class="ticket-detail-eyebrow">Support ticket <span>{{ $ticket->number }}</span></div>
            <h1>{{ $ticket->subject }}</h1>
            <p>Created {{ $ticket->created_at?->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('d M Y, h:i A') }} {{ config('ticketing.display_timezone', 'Asia/Dhaka') }} time · by {{ $ticket->creator?->name ?? 'A former user' }}</p>
        </div>
        @if($mayEditContent || $canStart)<div class="ticket-detail-heading-actions">
            @if($canStart)
                <form method="POST" action="{{ route('dashboard.tickets.transition', $ticket) }}">
                    @csrf @method('PATCH')
                    <input type="hidden" name="transition" value="start">
                    <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="lock_version" value="{{ $ticket->lock_version }}">
                    <button type="submit" class="btn btn-primary">Start work</button>
                </form>
            @endif
            @if($mayEditContent)<a class="btn btn-secondary admin-icon-button" href="{{ route('dashboard.tickets.edit', $ticket) }}" title="Edit ticket details" aria-label="Edit ticket details">@include('admin.action-icon', ['icon' => 'edit'])</a>@endif</div>@endif
    </header>

    @if(!$ticket->is_active)
        <div class="ticket-detail-disabled" role="status"><strong>Disabled ticket</strong><span>This ticket is read-only. Its SLA continues unless the ticket is waiting for the customer or resolved.</span></div>
    @endif

    <div class="ticket-detail-facts {{ !$isAdmin ? 'ticket-agent-facts' : '' }}" aria-label="Ticket summary">
        <div class="ticket-fact"><span class="ticket-fact-icon" aria-hidden="true">●</span><div class="ticket-fact-copy"><span class="ticket-fact-label">Status</span><span class="ticket-fact-value"><span class="status-badge ticket-status-{{ $ticket->status->value }}">{{ $statusLabels[$ticket->status->value] }}</span></span></div></div>
        <div class="ticket-fact"><span class="ticket-fact-icon" aria-hidden="true">↗</span><div class="ticket-fact-copy"><span class="ticket-fact-label">Priority</span><span class="ticket-fact-value">{{ $ticket->priority?->name ?? 'Not set' }}</span><span class="ticket-fact-sub">{{ $ticket->category?->name ?? 'Uncategorized' }}</span></div></div>
        @if($isAdmin)<div class="ticket-fact"><span class="ticket-fact-icon" aria-hidden="true">◎</span><div class="ticket-fact-copy"><span class="ticket-fact-label">Assigned agent</span><span class="ticket-fact-value">{{ $ticket->assignedAgent?->name ?? 'Unassigned' }}</span></div></div>@endif
        <div class="ticket-fact"><span class="ticket-fact-icon" aria-hidden="true">◷</span><div class="ticket-fact-copy"><span class="ticket-fact-label">SLA</span><span class="ticket-fact-value">{{ $slaStateLabels[$sla['state']] ?? str($sla['state'])->headline() }}</span>@if($sla['due_at'])<span class="ticket-fact-sub">Due {{ $sla['due_at']->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('d M, h:i A') }}</span>@elseif($ticket->status->value === 'waiting_customer')<span class="ticket-fact-sub">Paused for customer</span>@endif</div></div>
    </div>

    <div class="ticket-detail-layout">
        <div class="ticket-detail-main">
            <details class="card ticket-panel ticket-issue-accordion" id="ticket-issue-details">
                <summary class="ticket-issue-summary" aria-controls="ticket-issue-body">
                    <span class="ticket-issue-icon" aria-hidden="true">≡</span>
                    <span class="ticket-issue-summary-copy"><strong>Issue description</strong><small>Original details provided by the customer</small></span>
                    @if($ticket->attachments->isNotEmpty())<span class="ticket-issue-attachment-count">{{ $ticket->attachments->count() }} {{ \Illuminate\Support\Str::plural('attachment', $ticket->attachments->count()) }}</span>@endif
                    <span class="ticket-issue-chevron" aria-hidden="true">⌄</span>
                </summary>
                <div class="ticket-panel-body" id="ticket-issue-body">
                    <div class="ticket-description ticket-description-text">{{ $ticket->description }}</div>
                    @if($ticket->attachments->isNotEmpty())
                        <ul class="ticket-attachment-list" aria-label="Ticket attachments">
                            @foreach($ticket->attachments as $attachment)
                                <li class="ticket-attachment-item">
                                    <div class="ticket-attachment-name"><span aria-hidden="true">▧</span><span>{{ $attachment->original_name }}<span class="ticket-attachment-meta">{{ number_format($attachment->size_bytes / 1024, 0) }} KiB</span></span></div>
                                    <div class="ticket-attachment-actions">
                                        @if($attachment->is_active && !$attachment->trashed())<a href="{{ route('dashboard.ticket-attachments.download', $attachment) }}">Download</a>@else<span class="ticket-attachment-meta">Unavailable</span>@endif
                                        @if($isAdmin && auth()->user()->can('attachments.moderate'))
                                            <form method="POST" action="{{ route('dashboard.tickets.attachments.moderate', [$ticket, $attachment]) }}" class="ticket-moderation-form">@csrf<input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="expected_updated_at" value="{{ $attachment->updated_at?->format('Y-m-d\TH:i:s.uP') }}"><select name="action" class="form-input" required>@if($attachment->trashed())<option value="restore">Restore</option>@elseif($attachment->is_active)<option value="disable">Disable</option><option value="archive">Archive</option>@else<option value="enable">Enable</option><option value="archive">Archive</option>@endif</select><input name="reason" class="form-input" required minlength="5" maxlength="500" placeholder="Reason"><button class="btn btn-secondary" type="submit">Save</button></form>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </details>

            @include('admin.tickets.partials.conversation')
            <a class="ticket-back-link" href="{{ route('dashboard.tickets.index') }}"><span aria-hidden="true">←</span> Back to tickets</a>
        </div>

        <aside class="ticket-detail-sidebar" aria-label="Ticket information and actions">
            @if($isAdmin)
                <section class="card ticket-panel">
                    <div class="ticket-panel-heading"><div><h2>Customer</h2><p>Ticket requester</p></div></div>
                    <div class="ticket-sidebar-section">
                        <div class="ticket-customer-row"><span class="ticket-customer-avatar" aria-hidden="true">{{ str($ticket->customer?->name ?? 'U')->substr(0, 1)->upper() }}</span><div><div class="ticket-sidebar-value">{{ $ticket->customer?->name ?? 'Former user' }}</div><div class="ticket-sidebar-sub">{{ $ticket->customer?->email ?? 'Email unavailable' }}</div></div></div>
                    </div>
                </section>
            @endif

            <section class="card ticket-panel">
                <div class="ticket-panel-heading"><div><h2>Resolution SLA</h2><p>Business calendar: {{ $ticket->businessCalendar?->timezone ?? 'Asia/Dhaka' }}</p></div></div>
                <div class="ticket-sidebar-section" data-sla-countdown="{{ json_encode($sla) }}" data-sla-ticket="{{ $ticket->id }}">
                    <div class="ticket-sidebar-value ticket-sla-state ticket-sla-{{ $sla['state'] }}"><span class="ticket-sla-dot"></span><span data-sla-status>{{ $slaStateLabels[$sla['state']] ?? str($sla['state'])->headline() }}</span></div>
                    <div class="ticket-sla-remaining" data-sla-clock role="timer">{{ sprintf('%02d:%02d:%02d', intdiv($remainingSeconds, 3600), intdiv($remainingSeconds % 3600, 60), $remainingSeconds % 60) }}</div>
                    <p class="page-description" data-sla-clock-note>{{ $sla['state'] === 'paused' ? 'Paused while waiting for the customer' : ($sla['state'] === 'stopped' ? 'Timer stopped' : ($sla['state'] === 'breached' ? 'SLA target breached' : ($sla['counting_until'] ? 'Business time remaining · live' : 'Outside business hours'))) }}</p>
                    @if($sla['state'] === 'paused')
                        <p class="page-description">Paused since {{ $ticket->sla_paused_at?->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('d M Y, h:i A') }}. The clock resumes when the customer replies.</p>
                    @elseif($sla['state'] === 'stopped')
                        <p class="page-description">The resolution timer stopped when the ticket was resolved or closed.</p>
                    @else
                        @if($sla['due_at'])<p class="page-description">Due {{ $sla['due_at']->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('D, d M Y · h:i A') }}</p>@endif
                    @endif
                    @if($ticket->first_breached_at)<p class="page-description">First breached {{ $ticket->first_breached_at->timezone(config('ticketing.display_timezone', 'Asia/Dhaka'))->format('d M Y, h:i A') }} {{ config('ticketing.display_timezone', 'Asia/Dhaka') }} time.</p>@endif
                </div>
            </section>

            @if(($mayChangePriority && !in_array($ticket->status->value, ['resolved','closed'], true)) || ($mayAssign && $ticket->is_active && !in_array($ticket->status->value, ['resolved','closed'], true)) || count($secondaryTransitions) > 0)
                <section class="card ticket-panel ticket-actions-panel">
                    <div class="ticket-panel-heading"><div><h2>Manage ticket</h2><p>Changes are recorded in ticket history</p></div></div>
                    @if($mayChangePriority && !in_array($ticket->status->value, ['resolved','closed'], true))
                        <form method="POST" action="{{ route('dashboard.tickets.priority', $ticket) }}" class="ticket-action-group">@csrf @method('PATCH')<input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="lock_version" value="{{ $ticket->lock_version }}"><h3>Change priority</h3><label class="form-label" for="priority_id">Priority</label><select id="priority_id" name="priority_id" class="form-input" required>@foreach($priorities as $priority)<option value="{{ $priority->id }}" @selected($priority->id === $ticket->priority_id)>{{ $priority->name }}</option>@endforeach</select><p>The original SLA policy is retained; only its target and deadline are recalculated.</p><button class="btn btn-secondary" type="submit">Update priority</button></form>
                    @endif
                    @if($mayAssign && $ticket->is_active && !in_array($ticket->status->value, ['resolved','closed'], true))
                        <div class="ticket-action-group"><h3>Assignment</h3>@if($assignableAgents->isNotEmpty())<form method="POST" action="{{ route('dashboard.tickets.assignment', $ticket) }}" class="ticket-transition-form">@csrf @method('PATCH')<input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="lock_version" value="{{ $ticket->lock_version }}"><label class="form-label" for="agent_id">Assign to agent</label><select id="agent_id" name="agent_id" class="form-input" required><option value="">Select an agent</option>@foreach($assignableAgents as $agentProfile)<option value="{{ $agentProfile->user_id }}" @selected((int) $ticket->assigned_agent_id === (int) $agentProfile->user_id)>{{ $agentProfile->user?->name }} · {{ $agentProfile->user?->email }}</option>@endforeach</select><p>Assignment does not change status or reset the SLA.</p><button class="btn btn-secondary" type="submit">Save assignment</button></form>@else<p>No active agents are available for assignment.</p>@endif</div>
                    @endif
                    @if(count($secondaryTransitions) > 0)
                        <div class="ticket-action-group"><h3>Update status</h3>
                            @foreach($secondaryTransitions as $transition)
                                <form method="POST" enctype="multipart/form-data" action="{{ route('dashboard.tickets.transition', $ticket) }}" class="ticket-transition-form">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="transition" value="{{ $transition->value }}">
                                    <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                    <input type="hidden" name="lock_version" value="{{ $ticket->lock_version }}">

                                    @if(in_array($transition->value, ['wait', 'resolve', 'reopen'], true))
                                        <label class="form-label" for="message-{{ $transition->value }}">{{ $transition->value === 'wait' ? 'Explain what you need from the customer' : 'Resolution summary for the customer' }}</label>
                                        <textarea id="message-{{ $transition->value }}" name="public_message" rows="3" required minlength="5" maxlength="20000" @class(['form-input', 'is-invalid' => $errors->has('public_message')])></textarea>

                                    @endif

                                    @if($transition->value === 'reopen')
                                        <label class="form-label" for="reason-reopen">Reason for reopening</label>
                                        <textarea id="reason-reopen" name="reason" rows="3" required minlength="5" maxlength="500" @class(['form-input', 'is-invalid' => $errors->has('reason')])></textarea>

                                    @endif

                                    @if(in_array($transition->value, ['wait', 'resolve', 'reopen'], true))
                                        <label class="form-label" for="transition-files-{{ $transition->value }}">Attachments (optional)</label>
                                        <input id="transition-files-{{ $transition->value }}" name="attachments[]" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.txt,.csv" class="form-input">
                                    @endif
                                    <button class="btn btn-secondary" type="submit">{{ ['start' => 'Start work', 'wait' => 'Wait for customer', 'resume' => 'Resume work', 'resolve' => 'Resolve ticket', 'close' => 'Close ticket', 'reopen' => 'Reopen ticket'][$transition->value] }}</button>
                                </form>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif
        </aside>
    </div>
</div>
@endsection
