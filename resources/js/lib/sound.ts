let audio: AudioContext | null = null;

function context(): AudioContext | null {
    if (typeof AudioContext === 'undefined') {
        return null;
    }

    audio ??= new AudioContext();

    return audio;
}

export function unlockAudio(): void {
    const ctx = context();

    if (ctx?.state === 'suspended') {
        void ctx.resume();
    }
}

export function playBeep(): void {
    const ctx = context();

    if (ctx === null || ctx.state === 'suspended') {
        return;
    }

    const oscillator = ctx.createOscillator();
    const gain = ctx.createGain();
    const start = ctx.currentTime;

    oscillator.type = 'sine';
    oscillator.frequency.value = 880;
    gain.gain.setValueAtTime(0.0001, start);
    gain.gain.linearRampToValueAtTime(0.35, start + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.001, start + 0.25);

    oscillator.connect(gain).connect(ctx.destination);
    oscillator.start(start);
    oscillator.stop(start + 0.28);
}
