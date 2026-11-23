/* Funciones compartidas: carrito, WhatsApp, formato de precios. */
(function () {
  "use strict";

  const WHATSAPP = "56986558409";
  const CLAVE = "domicfit-carrito";

  const pesos = (n) => "$" + Math.round(n).toLocaleString("es-CL");
  const whatsappUrl = (texto) => `https://wa.me/${WHATSAPP}?text=${encodeURIComponent(texto)}`;
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

  /* ---------- Catálogo ---------- */
  let catalogoPromesa = null;
  function catalogo() {
    if (!catalogoPromesa) {
      catalogoPromesa = fetch("data/productos.json", { cache: "no-cache" })
        .then((r) => r.json())
        .then((d) => Object.fromEntries(d.productos.map((p) => [p.id, p])));
    }
    return catalogoPromesa;
  }

  /* ---------- Carrito (se guarda en este navegador) ---------- */
  function leer() {
    try {
      const datos = JSON.parse(localStorage.getItem(CLAVE) || "[]");
      return Array.isArray(datos) ? datos : [];
    } catch (e) { return []; }
  }
  function guardar(items) {
    try { localStorage.setItem(CLAVE, JSON.stringify(items)); } catch (e) { /* modo privado: el carrito dura mientras la página esté abierta */ }
    memoria = items;
    document.dispatchEvent(new CustomEvent("carrito:cambio"));
  }
  let memoria = leer();
  const items = () => memoria;

  async function agregar(id) {
    const cat = await catalogo();
    const p = cat[id];
    if (!p) return false;
    let actuales = items().filter((i) => cat[i.id]);
    const primero = actuales[0] && cat[actuales[0].id];

    // Una clase de prueba va sola; y todo el carrito debe ser de la misma modalidad.
    if (actuales.length && (p.tipo === "prueba" || primero.tipo === "prueba" || primero.modalidad !== p.modalidad)) {
      const motivo = p.tipo === "prueba" || primero.tipo === "prueba"
        ? "La clase de prueba se reserva sola."
        : `Tu carrito tiene productos ${primero.modalidad === "sede" ? "de la sede" : "a domicilio"}.`;
      if (!window.confirm(`${motivo} ¿Quieres vaciar el carrito y agregar "${p.nombre}"?`)) return false;
      actuales = [];
    }
    const existente = actuales.find((i) => i.id === id);
    if (existente) {
      if (p.tipo === "prueba") { abrirCarrito(); return true; }
      existente.cantidad = Math.min(5, existente.cantidad + 1);
    } else {
      actuales.push({ id, cantidad: 1 });
    }
    guardar(actuales);
    aviso(`Agregado: ${p.nombre}`);
    return true;
  }
  function cambiarCantidad(id, delta) {
    guardar(items().map((i) => i.id === id ? { ...i, cantidad: Math.max(1, Math.min(5, i.cantidad + delta)) } : i));
  }
  function quitar(id) { guardar(items().filter((i) => i.id !== id)); }
  function vaciar() { guardar([]); }

  async function detalle() {
    const cat = await catalogo();
    const lineas = items().filter((i) => cat[i.id]).map((i) => ({ ...cat[i.id], cantidad: cat[i.id].tipo === "prueba" ? 1 : i.cantidad }));
    const total = lineas.reduce((s, l) => s + l.precio * l.cantidad, 0);
    return { lineas, total, modalidad: lineas[0]?.modalidad || null, esPrueba: lineas[0]?.tipo === "prueba" };
  }

  /* ---------- Panel del carrito ---------- */
  const $ = (sel) => document.querySelector(sel);
  function abrirCarrito() {
    const panel = $("[data-carrito]");
    if (!panel) { location.href = "reservar.html"; return; }
    panel.classList.add("abierto");
    panel.setAttribute("aria-hidden", "false");
    $("[data-velo]").classList.add("visible");
    document.body.style.overflow = "hidden";
    panel.querySelector("[data-cerrar-carrito]").focus();
  }
  function cerrarCarrito() {
    const panel = $("[data-carrito]");
    if (!panel) return;
    panel.classList.remove("abierto");
    panel.setAttribute("aria-hidden", "true");
    $("[data-velo]").classList.remove("visible");
    document.body.style.overflow = "";
  }

  async function pintarCarrito() {
    const { lineas, total } = await detalle();
    const n = lineas.reduce((s, l) => s + l.cantidad, 0);
    document.querySelectorAll("[data-contador]").forEach((el) => { el.textContent = n; el.hidden = n === 0; });

    const lista = $("[data-carrito-lista]");
    if (!lista) return;
    if (!lineas.length) {
      lista.innerHTML = '<p class="carrito__vacio">Tu carrito está vacío.<br>Elige un plan para comenzar.</p>';
    } else {
      lista.innerHTML = lineas.map((l) => `
        <div class="linea-carrito">
          <div>
            <div class="linea-carrito__nombre">${esc(l.nombre)}</div>
            <div class="linea-carrito__detalle">${l.modalidad === "sede" ? "Sede San Joaquín" : "A domicilio"} · 1 o 2 personas</div>
          </div>
          <div class="linea-carrito__precio">${l.precio === 0 ? "Gratis" : pesos(l.precio * l.cantidad)}</div>
          ${l.tipo === "prueba" ? "<span></span>" : `
          <div class="cantidad" role="group" aria-label="Cantidad de ${esc(l.nombre)}">
            <button type="button" data-menos="${l.id}" aria-label="Quitar uno">−</button><span>${l.cantidad}</span><button type="button" data-mas="${l.id}" aria-label="Agregar uno">+</button>
          </div>`}
          <button type="button" class="quitar" data-quitar="${l.id}">Quitar</button>
        </div>`).join("");
    }
    const totalEl = $("[data-carrito-total]");
    if (totalEl) totalEl.textContent = pesos(total);
    const ir = $("[data-ir-reservar]");
    if (ir) {
      ir.classList.toggle("deshabilitado", !lineas.length);
      ir.setAttribute("aria-disabled", String(!lineas.length));
    }
  }

  /* ---------- Aviso flotante ---------- */
  let temporizador;
  function aviso(texto) {
    const t = $("[data-toast]");
    if (!t) return;
    t.textContent = texto;
    t.classList.add("visible");
    clearTimeout(temporizador);
    temporizador = setTimeout(() => t.classList.remove("visible"), 2600);
  }

  /* ---------- Eventos ---------- */
  document.addEventListener("click", (e) => {
    const t = e.target.closest("button, a");
    if (!t) return;
    if (t.matches("[data-abrir-carrito]")) abrirCarrito();
    else if (t.matches("[data-cerrar-carrito]")) cerrarCarrito();
    else if (t.dataset.mas) cambiarCantidad(t.dataset.mas, 1);
    else if (t.dataset.menos) cambiarCantidad(t.dataset.menos, -1);
    else if (t.dataset.quitar) quitar(t.dataset.quitar);
    else if (t.matches("[data-ir-reservar]") && !items().length) { e.preventDefault(); aviso("Agrega un plan al carrito primero."); }
  });
  document.addEventListener("keydown", (e) => { if (e.key === "Escape") cerrarCarrito(); });
  document.addEventListener("carrito:cambio", pintarCarrito);
  // Si el carrito cambia en otra pestaña
  window.addEventListener("storage", (e) => { if (e.key === CLAVE) { memoria = leer(); pintarCarrito(); } });

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelector("[data-velo]")?.addEventListener("click", cerrarCarrito);
    document.querySelectorAll("[data-whatsapp]").forEach((a) => {
      a.href = whatsappUrl(a.dataset.whatsapp);
      a.target = "_blank";
      a.rel = "noopener";
    });
    document.querySelectorAll("[data-anio]").forEach((el) => { el.textContent = new Date().getFullYear(); });

    const botonMenu = document.querySelector("[data-boton-menu]");
    const menuMovil = document.getElementById("menu-movil");
    if (botonMenu && menuMovil) {
      botonMenu.addEventListener("click", () => {
        const abierto = menuMovil.classList.toggle("abierto");
        botonMenu.setAttribute("aria-expanded", String(abierto));
      });
      menuMovil.addEventListener("click", (e) => {
        if (e.target.closest("a")) { menuMovil.classList.remove("abierto"); botonMenu.setAttribute("aria-expanded", "false"); }
      });
    }
    pintarCarrito();
  });

  window.Domicfit = { WHATSAPP, pesos, whatsappUrl, esc, catalogo, items, agregar, vaciar, detalle, abrirCarrito, aviso };
})();
