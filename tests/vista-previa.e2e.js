/*
 * Prueba en un navegador real la vista previa (modo demostración):
 * agregar al carrito, agendar, pagar (simulado) y ver el resultado, en computador y celular.
 *
 *   ./scripts/construir-vista-previa.sh _site
 *   (cd _site && python3 -m http.server 8095) &
 *   BASE_URL=http://localhost:8095/ node tests/vista-previa.e2e.js
 */
const { chromium, devices } = require("playwright");
const assert = require("node:assert/strict");

const BASE = process.env.BASE_URL || "http://localhost:8095/";
let fallos = 0;

async function prueba(nombre, fn) {
  try {
    await fn();
    console.log(`  ✓ ${nombre}`);
  } catch (e) {
    fallos++;
    console.log(`  ✗ ${nombre}\n      ${e.message.split("\n")[0]}`);
  }
}

async function completarDatos(p, { domicilio }) {
  await p.fill("[name=nombre]", "Camila Prueba");
  await p.fill("[name=email]", "camila@example.com");
  await p.fill("[name=telefono]", "+56 9 1111 2222");
  if (domicilio) {
    await p.selectOption("[name=comuna]", "Ñuñoa");
    await p.fill("[name=direccion]", "Irarrázaval 1234");
  }
  await p.locator(".dia").nth(2).click();
  await p.waitForSelector(".hora:not([disabled])");
  await p.locator(".hora:not([disabled])").first().click();
  await p.check("[name=acepta]");
}

(async () => {
  const navegador = await chromium.launch();
  const errores = [];

  for (const [dispositivo, opciones] of [["computador", { viewport: { width: 1366, height: 900 } }], ["celular", devices["iPhone 13"]]]) {
    console.log(`\nVista previa en ${dispositivo}`);
    const p = await (await navegador.newContext(opciones)).newPage();
    p.on("pageerror", (e) => errores.push(`${dispositivo}: ${e.message}`));
    p.on("dialog", (d) => d.accept());

    await prueba("la página carga los planes y muestra el aviso de vista previa", async () => {
      await p.goto(BASE);
      await p.waitForSelector(".plan");
      assert.equal(await p.locator(".plan").count(), 6);
      assert.match(await p.textContent(".aviso-demo"), /Vista previa/);
    });

    await prueba("no hay desplazamiento horizontal", async () => {
      const [ancho, ventana] = await p.evaluate(() => [document.documentElement.scrollWidth, innerWidth]);
      assert.ok(ancho <= ventana, `${ancho} > ${ventana}`);
    });

    await prueba("el carrito suma cantidades", async () => {
      await p.click('[data-agregar="dom-24"]');
      await p.waitForSelector(".carrito.abierto");
      await p.click('[data-mas="dom-24"]');
      assert.equal(await p.textContent("[data-carrito-total]"), "$1.176.000");
    });

    await prueba("compra a domicilio con pago simulado termina confirmada", async () => {
      await p.click("[data-ir-reservar]");
      await p.waitForSelector(".dia");
      await completarDatos(p, { domicilio: true });
      await p.click("[data-enviar]");
      await p.waitForURL(/pago\.html/);
      await p.waitForSelector(".resultado h1");
      assert.match(await p.textContent(".resultado h1"), /Listo/);
    });

    await prueba("la clase de prueba en sede se reserva gratis sin paso de pago", async () => {
      await p.goto(BASE + "#planes");
      await p.click('[data-prueba="prueba-sede"]');
      await p.waitForURL(/reservar/);
      await p.waitForSelector(".dia");
      assert.ok(await p.isHidden("[data-paso-pago]"));
      assert.ok(await p.isHidden("[data-solo-domicilio]"));
      await completarDatos(p, { domicilio: false });
      await p.click("[data-enviar]");
      await p.waitForURL(/pago\.html/);
      await p.waitForSelector(".resultado h1");
      assert.match(await p.textContent(".resultado h1"), /prueba reservada/i);
    });

    await prueba("sin datos no deja enviar la reserva", async () => {
      await p.goto(BASE + "#planes");
      await p.click('[data-pestana="sede"]');
      await p.click('[data-agregar="sede-8"]');
      await p.goto(BASE + "reservar.html");
      await p.waitForSelector(".dia");
      await p.click("[data-enviar]");
      assert.ok(await p.isVisible("[data-error]"));
      assert.match(p.url(), /reservar\.html/);
    });

    await prueba("las páginas legales cargan", async () => {
      for (const pagina of ["terminos.html", "privacidad.html"]) {
        await p.goto(BASE + pagina);
        assert.ok((await p.textContent("h1")).length > 5);
      }
    });
  }

  await prueba("no hubo errores de JavaScript", async () => {
    assert.deepEqual(errores, []);
  });

  await navegador.close();
  console.log(fallos ? `\n${fallos} prueba(s) fallaron.` : "\nTodas las pruebas del navegador pasaron.");
  process.exit(fallos ? 1 : 0);
})();
