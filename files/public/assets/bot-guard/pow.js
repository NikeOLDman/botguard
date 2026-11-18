/* SHA-256 proof of work. Only the first word is needed for the difficulty check. */
(function (root) {
    'use strict';
    var K = [1116352408,1899447441,3049323471,3921009573,961987163,1508970993,2453635748,2870763221,3624381080,310598401,607225278,1426881987,1925078388,2162078206,2614888103,3248222580,3835390401,4022224774,264347078,604807628,770255983,1249150122,1555081692,1996064986,2554220882,2821834349,2952996808,3210313671,3336571891,3584528711,113926993,338241895,666307205,773529912,1294757372,1396182291,1695183700,1986661051,2177026350,2456956037,2730485921,2820302411,3259730800,3345764771,3516065817,3600352804,4094571909,275423344,430227734,506948616,659060556,883997877,958139571,1322822218,1537002063,1747873779,1955562222,2024104815,2227730452,2361852424,2428436474,2756734187,3204031479,3329325298];
    function rotate(x, n) { return (x >>> n) | (x << (32 - n)); }
    function firstWord(text) {
        var words = [], w = new Int32Array(64), length = text.length, i, j;
        for (i = 0; i < length; i++) words[i >> 2] = (words[i >> 2] || 0) | (text.charCodeAt(i) << (24 - (i % 4) * 8));
        words[length >> 2] = (words[length >> 2] || 0) | (128 << (24 - (length % 4) * 8));
        var blocks = Math.ceil((length + 9) / 64);
        words[blocks * 16 - 1] = length * 8;
        var h = [1779033703,3144134277,1013904242,2773480762,1359893119,2600822924,528734635,1541459225];
        for (i = 0; i < blocks * 16; i += 16) {
            for (j = 0; j < 64; j++) {
                if (j < 16) w[j] = words[i + j] || 0;
                else {
                    var x = w[j - 15], y = w[j - 2];
                    w[j] = (w[j - 16] + (rotate(x,7) ^ rotate(x,18) ^ (x >>> 3)) + w[j - 7] + (rotate(y,17) ^ rotate(y,19) ^ (y >>> 10))) | 0;
                }
            }
            var a=h[0],b=h[1],c=h[2],d=h[3],e=h[4],f=h[5],g=h[6],v=h[7];
            for (j = 0; j < 64; j++) {
                var t1 = (v + (rotate(e,6)^rotate(e,11)^rotate(e,25)) + ((e&f)^((~e)&g)) + K[j] + w[j]) | 0;
                var t2 = ((rotate(a,2)^rotate(a,13)^rotate(a,22)) + ((a&b)^(a&c)^(b&c))) | 0;
                v=g;g=f;f=e;e=(d+t1)|0;d=c;c=b;b=a;a=(t1+t2)|0;
            }
            h[0]=(h[0]+a)|0;h[1]=(h[1]+b)|0;h[2]=(h[2]+c)|0;h[3]=(h[3]+d)|0;
            h[4]=(h[4]+e)|0;h[5]=(h[5]+f)|0;h[6]=(h[6]+g)|0;h[7]=(h[7]+v)|0;
        }
        return h[0] >>> 0;
    }
    function solve(seed, bits, callback) {
        var next = 0, deadline = Date.now() + 20000;
        function batch() {
            for (var end = next + 512; next < end; next++) {
                if ((firstWord(seed + ':' + next) >>> (32 - bits)) === 0) { callback(null, String(next)); return; }
            }
            if (Date.now() > deadline) { callback(new Error('timeout')); return; }
            setTimeout(batch, 0);
        }
        if (!/^[a-f0-9]{64}$/.test(seed) || bits < 8 || bits > 20) { callback(new Error('invalid')); return; }
        batch();
    }
    root.BotGuardPow = {firstWord: firstWord, solve: solve};
    if (typeof document === 'undefined' && typeof root.postMessage === 'function') {
        root.onmessage = function (event) {
            solve(event.data.seed, event.data.bits, function (error, solution) {
                root.postMessage(error ? {error: error.message} : {solution: solution});
            });
        };
    }
})(typeof self !== 'undefined' ? self : this);
