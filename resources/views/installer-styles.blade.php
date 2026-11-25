<style>
    :root {
        color-scheme: light;
        font-family: "Open Sans", ui-sans-serif, system-ui, sans-serif;
        color: #1e293b;
        background: #f5f8f2;
    }

    * { box-sizing: border-box; }
    body {
        min-height: 100vh;
        margin: 0;
        padding: 2rem 1rem;
        background: radial-gradient(ellipse at top left, #e6f0d7, transparent 52%), #f5f8f2;
    }
    main { width: min(100%, 42rem); margin: 0 auto; }
    .card {
        padding: clamp(1.5rem, 5vw, 2.5rem);
        border: 1px solid rgba(255, 255, 255, .8);
        border-radius: 1.5rem;
        background: rgba(255, 255, 255, .9);
        box-shadow: 0 20px 35px -15px rgba(20, 83, 45, .2);
    }
    .brand { display: flex; justify-content: center; }
    .brand img { width: 13rem; max-width: 100%; padding: .5rem; border-radius: .75rem; background: white; }
    .heading { margin: 1.5rem 0 0; text-align: center; font-size: 1.5rem; font-weight: 800; letter-spacing: -.025em; color: #262626; }
    .intro { margin: .5rem auto 0; max-width: 32rem; text-align: center; font-size: .9rem; line-height: 1.5; color: #475569; }
    .steps { display: flex; justify-content: center; align-items: center; gap: .75rem; margin: 1.75rem 0; padding: 0; list-style: none; }
    .step { display: flex; align-items: center; gap: .5rem; font-size: .8rem; font-weight: 700; color: #64748b; }
    .step-number { display: grid; width: 1.75rem; height: 1.75rem; place-items: center; border-radius: 50%; background: #e2e8f0; }
    .step-current { color: #3d6412; }
    .step-current .step-number { background: #4d7d16; color: white; }
    .step-divider { width: 2rem; height: 1px; background: #cbd5e1; }
    .form { display: grid; gap: 1rem; margin-top: 1.5rem; }
    .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
    .field { display: block; font-size: .875rem; font-weight: 600; color: #334155; }
    .field input, .field select {
        display: block;
        width: 100%;
        margin-top: .5rem;
        padding: .75rem 1rem;
        border: 1px solid #cbd5e1;
        border-radius: .75rem;
        background: #fff;
        color: #0f172a;
        font: inherit;
        font-size: 1rem;
    }
    .field input:focus, .field select:focus { outline: 2px solid rgba(168, 212, 46, .5); outline-offset: 1px; border-color: #79a52b; }
    .field input::placeholder { color: #94a3b8; }
    .hint { margin: .4rem 0 0; font-size: .75rem; font-weight: 400; line-height: 1.5; color: #64748b; }
    .checkbox { display: flex; align-items: flex-start; gap: .65rem; font-size: .875rem; font-weight: 600; color: #334155; }
    .checkbox input { flex: none; width: 1rem; height: 1rem; margin: .2rem 0 0; accent-color: #4d7d16; }
    .notice { display: flex; gap: .75rem; padding: 1rem; border: 1px solid #fecaca; border-radius: .75rem; background: #fef2f2; color: #991b1b; }
    .notice svg { width: 1.25rem; height: 1.25rem; flex: none; }
    .notice-title { margin: 0; font-size: .875rem; font-weight: 700; }
    .notice ul { margin: .35rem 0 0; padding-left: 1.15rem; font-size: .875rem; line-height: 1.5; }
    .destination { margin-top: 1.5rem; padding: 1rem; border: 1px solid #d9e8c2; border-radius: .75rem; background: #f4f9e9; font-size: .875rem; line-height: 1.5; overflow-wrap: anywhere; }
    .destination p { margin: 0; }
    .destination p + p { margin-top: .5rem; }
    .button { display: block; width: 100%; padding: .8rem 1rem; border: 0; border-radius: 9999px; background: #4d7d16; color: white; font: inherit; font-weight: 700; text-align: center; text-decoration: none; cursor: pointer; }
    .button:hover { background: #3d6412; }
    .button:focus-visible { outline: 3px solid #a8d42e; outline-offset: 3px; }
    .success-icon { display: grid; width: 4rem; height: 4rem; margin: 1.75rem auto 0; place-items: center; border-radius: 50%; background: #e6f2d3; color: #4d7d16; }
    .success-icon svg { width: 2rem; height: 2rem; }
    .success-action { margin-top: 1.75rem; }

    @media (max-width: 34rem) {
        body { padding: 1rem; }
        .fields { grid-template-columns: 1fr; }
        .steps { gap: .5rem; }
        .step-divider { width: 1rem; }
    }

    @media (prefers-color-scheme: dark) {
        :root { color-scheme: dark; color: #e2e8f0; background: #0c1510; }
        body { background: radial-gradient(ellipse at top left, #263c16, transparent 50%), #0c1510; }
        .card { border-color: rgba(255, 255, 255, .15); background: #17251a; box-shadow: 0 20px 35px -15px rgba(0, 0, 0, .45); }
        .heading { color: #f1f5f9; }
        .intro, .hint { color: #cbd5e1; }
        .step { color: #cbd5e1; }
        .step-number { background: #334155; }
        .step-current { color: #b6e04c; }
        .step-current .step-number { background: #4d7d16; }
        .step-divider { background: #475569; }
        .field, .checkbox { color: #e2e8f0; }
        .field input, .field select { border-color: rgba(255, 255, 255, .2); background: #101b14; color: #f1f5f9; }
        .notice { border-color: #991b1b; background: #450a0a; color: #fecaca; }
        .destination { border-color: #4d7d16; background: #243719; }
        .success-icon { background: #304d19; color: #b6e04c; }
    }
</style>
