/* Página principal: planes, equipo, galería y testimonios. */
(function () {
  "use strict";
  const { pesos, esc, catalogo, agregar, items, abrirCarrito } = window.Domicfit;

  const NOTAS = {
    domicilio: "Mismo valor en todas las comunas de Santiago. El profesor lleva los implementos.",
    sede: "Pasaje Río Valdivia 3705B, San Joaquín. Personalizado y 2x1: ven solo o con otra persona por el mismo valor.",
  };
  let pestanaActual = "domicilio";

  async function pintarPlanes() {
    const lista = document.getElementById("planes-lista");
    let cat;
    try { cat = await catalogo(); } catch (e) {
      lista.innerHTML = '<p class="nota">No pudimos cargar los planes. Recarga la página o escríbenos por WhatsApp.</p>';
      return;
    }
    const enCarrito = new Set(items().map((i) => i.id));
    const planes = Object.values(cat).filter((p) => p.tipo === "plan" && p.sesiones > 1 && p.modalidad === pestanaActual);
    document.querySelector("[data-suelta]").hidden = pestanaActual !== "domicilio";
    lista.innerHTML = planes.map((p) => {
      const porSesion = Math.round(p.precio / p.sesiones);
      return `
        <article class="plan${p.mejor_valor ? " plan--mejor" : ""}">
          ${p.mejor_valor ? '<span class="etiqueta">Mejor valor</span>' : ""}
          <div class="plan__sesiones"><b>${p.sesiones}</b>Sesiones</div>
          <div class="plan__precio">${pesos(p.precio)}</div>
          <div class="plan__por">${pesos(porSesion)} por sesión</div>
          <button type="button" class="boton boton--chico boton--bloque${enCarrito.has(p.id) ? " agregado" : ""}" data-agregar="${esc(p.id)}">
            ${enCarrito.has(p.id) ? "En el carrito ✓" : "Agregar"}
          </button>
        </article>`;
    }).join("");
    document.querySelector("[data-nota-planes]").textContent = NOTAS[pestanaActual];
  }

  function elegirPestana(modalidad) {
    pestanaActual = modalidad;
    document.querySelectorAll("[data-pestana]").forEach((b) => b.setAttribute("aria-selected", String(b.dataset.pestana === modalidad)));
    pintarPlanes();
  }

  async function pintarJson(url, fn) {
    try {
      const r = await fetch(url, { cache: "no-cache" });
      fn(await r.json());
    } catch (e) { /* si falla, la sección queda oculta o con su contenido por defecto */ }
  }

  document.addEventListener("click", async (e) => {
    const b = e.target.closest("button, a");
    if (!b) return;
    if (b.dataset.pestana) elegirPestana(b.dataset.pestana);
    if (b.dataset.irPestana) elegirPestana(b.dataset.irPestana);
    if (b.dataset.agregar) {
      if (await agregar(b.dataset.agregar)) abrirCarrito();
    }
    if (b.dataset.prueba) {
      if (await agregar(b.dataset.prueba)) location.href = "reservar.html";
    }
  });

  // Flechas del teclado entre pestañas
  document.querySelector(".pestanas")?.addEventListener("keydown", (e) => {
    if (e.key !== "ArrowRight" && e.key !== "ArrowLeft") return;
    const siguiente = pestanaActual === "domicilio" ? "sede" : "domicilio";
    elegirPestana(siguiente);
    document.getElementById("tab-" + siguiente).focus();
  });

  document.addEventListener("carrito:cambio", pintarPlanes);

  document.addEventListener("DOMContentLoaded", () => {
    pintarPlanes();

    pintarJson("data/equipo.json", (d) => {
      document.querySelector("[data-equipo]").innerHTML = (d.equipo || []).map((p) => `
        <article class="profe">
          <div class="profe__foto">${p.foto
            ? `<img src="${esc(p.foto)}" alt="${esc(p.nombre)}" loading="lazy">`
            : '<img class="sin-foto" src="assets/img/logo-domicfit-240.png" alt="" loading="lazy">'}</div>
          <div class="profe__datos">
            <h3>${esc(p.nombre)}</h3>
            <div class="profe__cargo">${esc(p.cargo)}</div>
            ${p.descripcion ? `<p>${esc(p.descripcion)}</p>` : ""}
            ${(p.formacion || []).length ? `<ul>${p.formacion.map((f) => `<li>${esc(f)}</li>`).join("")}</ul>` : ""}
            <div class="chips">${(p.modalidades || []).map((m) => `<span>${esc(m)}</span>`).join("")}</div>
          </div>
        </article>`).join("");
    });

    pintarJson("data/galeria.json", (d) => {
      const fotos = d.fotos || [];
      if (!fotos.length) return;
      document.querySelector("[data-galeria]").innerHTML = fotos.map((f) =>
        `<img src="${esc(f.archivo)}" alt="${esc(f.descripcion || "Alumno de Domicfit entrenando")}" loading="lazy">`).join("");
      document.getElementById("galeria").hidden = false;
    });

    pintarJson("data/testimonios.json", (d) => {
      const lista = d.testimonios || [];
      if (!lista.length) return;
      document.querySelector("[data-testimonios]").innerHTML = lista.map((t) => `
        <figure class="testimonio">
          <blockquote>${esc(t.texto)}</blockquote>
          <figcaption><b>${esc(t.nombre)}</b>${t.modalidad ? " · " + esc(t.modalidad) : ""}</figcaption>
        </figure>`).join("");
      document.getElementById("testimonios").hidden = false;
    });
  });
})();
