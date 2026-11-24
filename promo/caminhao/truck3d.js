// Caminhão 3D (cavalo mecânico + carreta baú) reutilizável pelas cenas.
// Convenção: o caminhão olha para +Z; origem no chão, na frente da cabine.
import * as THREE from 'three';
import { RoundedBoxGeometry } from './vendor/RoundedBoxGeometry.js';

function canvasTex(w, h, draw) {
  const c = document.createElement('canvas'); c.width = w; c.height = h;
  draw(c.getContext('2d'), w, h);
  const t = new THREE.CanvasTexture(c);
  t.colorSpace = THREE.SRGBColorSpace; t.anisotropy = 16; t.wrapS = t.wrapT = THREE.RepeatWrapping;
  return t;
}

export function buildTruck(color) {
  const truck = new THREE.Group();
  const paint = new THREE.MeshPhysicalMaterial({ color, metalness: .38, roughness: .34, clearcoat: 1, clearcoatRoughness: .1, envMapIntensity: 1.25 });
  const dark = new THREE.MeshStandardMaterial({ color: '#1a1d21', roughness: .55, metalness: .35 });
  const rubber = new THREE.MeshStandardMaterial({ color: '#121315', roughness: .95 });
  const chrome = new THREE.MeshStandardMaterial({ color: '#eef2f6', metalness: 1, roughness: .16 });
  const steel = new THREE.MeshStandardMaterial({ color: '#8a929a', metalness: .9, roughness: .4 });
  const glass = new THREE.MeshPhysicalMaterial({ color: '#12212f', metalness: .15, roughness: .04, clearcoat: 1, envMapIntensity: 2.4 });
  const lampMat = new THREE.MeshStandardMaterial({ color: '#ffffff', emissive: '#fff4d2', emissiveIntensity: 2.4 });
  const ribTex = canvasTex(256, 64, (g, w, h) => { g.fillStyle = '#f1f3f5'; g.fillRect(0, 0, w, h); g.fillStyle = '#d9dde1'; for (let x = 0; x < w; x += 16) g.fillRect(x, 0, 3, h); });
  ribTex.repeat.set(40, 1);
  const trailerMat = new THREE.MeshStandardMaterial({ color: '#e9ecef', map: ribTex, roughness: .55, metalness: .08 });

  const add = (geo, mat, x, y, z, shadow = true) => { const m = new THREE.Mesh(geo, mat); m.position.set(x, y, z); m.castShadow = shadow; m.receiveShadow = true; truck.add(m); return m; };
  const RB = (w, h, d, r = .1, s = 5) => new RoundedBoxGeometry(w, h, d, s, r);

  // cabine
  add(RB(2.5, 2.4, 2.55, .24, 6), paint, 0, 2.15, -1.27);
  const defl = add(RB(2.36, .5, 1.5, .2, 5), paint, 0, 3.52, -1.75); defl.rotation.x = .1;
  add(RB(2.54, .26, 2.2, .06), dark, 0, 1.02, -1.35);
  const ws = add(new THREE.BoxGeometry(2.12, 1.12, .07), glass, 0, 2.42, .03); ws.rotation.x = -.1;
  add(RB(2.34, .13, .5, .05), dark, 0, 3.07, -.04);
  add(RB(2.36, .06, .06, .02), dark, 0, 1.84, .07);
  // grade, faróis, para-choque
  add(RB(1.74, .72, .1, .05), dark, 0, 1.43, .05);
  for (let i = 0; i < 5; i++) add(new THREE.BoxGeometry(1.6, .035, .05), chrome, 0, 1.17 + i * .15, .12, false);
  add(new THREE.CylinderGeometry(.14, .14, .04, 24), chrome, 0, 1.47, .12, false).rotation.x = Math.PI / 2;
  const lamps = [];
  for (const sx of [-1, 1]) {
    lamps.push(add(RB(.62, .32, .14, .06), lampMat, sx * 1.0, 1.35, .03, false));
    add(RB(.66, .08, .08, .03), lampMat, sx * 1.0, 1.58, .03, false);
    add(new THREE.CylinderGeometry(.09, .09, .05, 16), lampMat, sx * .78, .78, .27, false).rotation.x = Math.PI / 2;
  }
  add(RB(2.54, .44, .34, .12), dark, 0, .78, .1);
  add(RB(1.6, .2, .26, .06), dark, 0, .52, .0);
  add(new THREE.BoxGeometry(.54, .13, .02), new THREE.MeshStandardMaterial({ color: '#f4f4f0', roughness: .5 }), 0, .86, .28, false);
  // laterais
  for (const sx of [-1, 1]) {
    add(new THREE.BoxGeometry(.07, .98, 1.2), glass, sx * 1.26, 2.4, -.62);
    add(new THREE.BoxGeometry(.07, .5, .95), glass, sx * 1.26, 2.75, -1.95);
    add(new THREE.BoxGeometry(.03, 1.25, .03), dark, sx * 1.26, 1.95, -1.32, false);
    add(new THREE.BoxGeometry(.03, .06, .22), chrome, sx * 1.27, 2.0, -1.0, false);
    add(new THREE.CylinderGeometry(.025, .025, .5, 8), dark, sx * 1.5, 2.1, -.15, false).rotation.z = Math.PI / 2;
    add(RB(.16, .66, .3, .06), dark, sx * 1.72, 2.36, -.15);
    add(new THREE.BoxGeometry(.02, .56, .24), chrome, sx * 1.8, 2.36, -.15, false);
    add(RB(.5, .1, .5, .03), steel, sx * 1.36, .92, -.6, false);
    add(new THREE.BoxGeometry(.04, .09, .26), new THREE.MeshStandardMaterial({ color: '#ff9a1a', emissive: '#ff8a00', emissiveIntensity: .8 }), sx * 1.27, 3.1, -.1, false);
  }
  add(new THREE.CylinderGeometry(.1, .1, 3.0, 14), chrome, 1.5, 2.45, -2.6);
  add(new THREE.CylinderGeometry(.11, .11, .12, 14), dark, 1.5, 3.99, -2.6, false);
  const tank = add(new THREE.CylinderGeometry(.34, .34, 1.5, 24), chrome, 1.28, .75, -2.2); tank.rotation.x = Math.PI / 2;
  const tank2 = add(new THREE.CylinderGeometry(.3, .3, 1.2, 24), steel, -1.28, .75, -2.2); tank2.rotation.x = Math.PI / 2;
  // chassi + carreta
  add(RB(1.1, .3, 15.5, .05), dark, 0, .82, -8.0);
  add(RB(2.0, .12, 1.4, .04), dark, 0, 1.2, -3.1);
  const trailer = add(RB(2.62, 2.8, 12.2, .06), trailerMat, 0, 2.8, -8.8);
  add(RB(2.66, .2, 12.2, .04), new THREE.MeshStandardMaterial({ color: '#c9ced3', roughness: .6 }), 0, 1.46, -8.8, false);
  add(RB(2.66, .1, 12.25, .03), new THREE.MeshStandardMaterial({ color: '#d7dbdf', roughness: .5 }), 0, 4.2, -8.8, false);
  for (const sx of [-1, 1]) {
    const tape = new THREE.Mesh(new THREE.PlaneGeometry(12, .09), new THREE.MeshStandardMaterial({ color: '#d3262b', roughness: .4, emissive: '#400000' }));
    tape.position.set(sx * 1.32, 1.58, -8.8); tape.rotation.y = sx * Math.PI / 2; truck.add(tape);
    add(RB(.06, .32, 6.0, .02), dark, sx * 1.2, 1.1, -7.4, false);
  }
  // rodas
  const wheels = [];
  function wheel(z, dual = false) {
    const make = (px) => {
      const g = new THREE.Group(); g.position.set(px, .53, z);
      const tire = new THREE.Mesh(new THREE.CylinderGeometry(.53, .53, .3, 40), rubber); tire.rotation.z = Math.PI / 2; tire.castShadow = true; g.add(tire);
      const rim = new THREE.Mesh(new THREE.CylinderGeometry(.34, .34, .32, 32), chrome); rim.rotation.z = Math.PI / 2; g.add(rim);
      const inner = new THREE.Mesh(new THREE.CylinderGeometry(.24, .24, .335, 32), steel); inner.rotation.z = Math.PI / 2; g.add(inner);
      for (let i = 0; i < 10; i++) {
        const a = i / 10 * Math.PI * 2;
        const n = new THREE.Mesh(new THREE.CylinderGeometry(.028, .028, .345, 8), chrome); n.rotation.z = Math.PI / 2; n.position.set(0, Math.cos(a) * .17, Math.sin(a) * .17); g.add(n);
      }
      const hub = new THREE.Mesh(new THREE.CylinderGeometry(.09, .09, .36, 16), steel); hub.rotation.z = Math.PI / 2; g.add(hub);
      truck.add(g); wheels.push(g);
    };
    for (const sx of [-1, 1]) { make(sx * 1.02); if (dual) make(sx * 1.36); }
    add(RB(.2, .06, 1.15, .02), dark, 1.02, 1.14, z, false); add(RB(.2, .06, 1.15, .02), dark, -1.02, 1.14, z, false);
  }
  wheel(-.9); wheel(-3.3, true); wheel(-4.6, true); wheel(-12.2, true); wheel(-13.5, true); wheel(-14.8, true);
  // brilho dos faróis
  const hc = document.createElement('canvas'); hc.width = hc.height = 128;
  { const g = hc.getContext('2d'), gr = g.createRadialGradient(64, 64, 0, 64, 64, 64);
    gr.addColorStop(0, 'rgba(255,248,220,1)'); gr.addColorStop(.25, 'rgba(255,235,170,.55)'); gr.addColorStop(1, 'rgba(255,220,140,0)'); g.fillStyle = gr; g.fillRect(0, 0, 128, 128); }
  const haloTex = new THREE.CanvasTexture(hc); haloTex.colorSpace = THREE.SRGBColorSpace;
  const halos = [];
  for (const sx of [-1, 1]) {
    const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: haloTex, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending, fog: false }));
    s.position.set(sx * 1.0, 1.38, .3); s.scale.set(1.2, 1.2, 1); truck.add(s); halos.push(s);
  }
  return { truck, wheels, trailer, halos, lamps, stack: new THREE.Vector3(1.5, 4.05, -2.6) };
}
