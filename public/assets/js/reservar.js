/* Página de reserva: datos, día y hora, y pago. */
(function () {
  "use strict";
  const { pesos, esc, detalle, vaciar } = window.Domicfit;

  const COMUNAS = ["Cerrillos", "Cerro Navia", "Conchalí", "El Bosque", "Estación Central", "Huechuraba", "Independencia",
    "La Cisterna", "La Florida", "La Granja", "La Pintana", "La Reina", "Las Condes", "Lo Barnechea", "Lo Espejo", "Lo Prado",
    "Macul", "Maipú", "Ñuñoa", "Pedro Aguirre Cerda", "Peñalolén", "Providencia", "Pudahuel", "Puente Alto", "Quilicura",
    "Quinta Normal", "Recoleta", "Renca", "San Bernardo", "San Joaquín", "San Miguel", "San Ramón", "Santiago", "Vitacura", "Otra"];
  const DIAS = ["dom", "lun", "mar", "mié", "jue", "vie", "sáb"];
  const MESES = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"];

  const form = document.querySelector("[data-formulario]");
  const $ = (s) => document.querySelector(s);
  let pedido, opciones, fechaElegida = null, horaElegida = null, enviando = false;

  const fechaTexto = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  const diaIso = (d) => (d.getDay() === 0 ? 7 : d.getDay()); // 1 = lunes ... 7 = domingo

  function mostrarError(texto) {
    const e = $("[data-error]");
    e.textContent = texto;
    e.hidden = !texto;
    if (texto) e.scrollIntoView({ behavior: "smooth", block: "center" });
  }

  function pintarResumen() {
    $("[data-resumen-items]").innerHTML = pedido.lineas.map((l) =>
      `<div class="resumen__item"><span>${esc(l.nombre)}${l.cantidad > 1 ? " x" + l.cantidad : ""}</span><span>${l.precio === 0 ? "Gratis" : pesos(l.precio * l.cantidad)}</span></div>`).join("");
    $("[data-resumen-total]").textContent = pedido.total === 0 ? "Gratis" : pesos(pedido.total);
    $("[data-resumen-lugar]").textContent = pedido.modalidad === "sede" ? "Sede San Joaquín" : "A domicilio";
    if (fechaElegida && horaElegida) {
      const [a, m, d] = fechaElegida.split("-").map(Number);
      const f = new Date(a, m - 1, d);
      $("[data-resumen-fecha]").textContent = `${DIAS[f.getDay()]} ${d} ${MESES[m - 1]}, ${horaElegida}`;
    } else {
      $("[data-resumen-fecha]").textContent = "Por elegir";
    }
  }

  function pintarDias() {
    const cont = $("[data-dias]");
    const [a, m, d] = opciones.hoy.split("-").map(Number);
    const hoy = new Date(a, m - 1, d);
    const habiles = opciones.dias_con_atencion[pedido.modalidad] || [];
    const botones = [];
    for (let i = 0; i <= opciones.dias_maximos; i++) {
      const f = new Date(hoy);
      f.setDate(hoy.getDate() + i);
      if (!habiles.includes(diaIso(f))) continue;
      botones.push(`<button type="button" class="dia" data-fecha="${fechaTexto(f)}" aria-pressed="false">
        <small>${DIAS[f.getDay()]}</small><b>${f.getDate()}</b><small>${MESES[f.getMonth()]}</small></button>`);
    }
    cont.innerHTML = botones.join("");
  }

  async function cargarHoras(fecha) {
    fechaElegida = fecha;
    horaElegida = null;
    pintarResumen();
    document.querySelectorAll("[data-fecha]").forEach((b) => b.setAttribute("aria-pressed", String(b.dataset.fecha === fecha)));
    const cont = $("[data-horas]");
    cont.innerHTML = '<p class="estado-carga">Buscando horas disponibles…</p>';
    try {
      const r = await fetch(`api/disponibilidad.php?modalidad=${pedido.modalidad}&fecha=${fecha}`, { cache: "no-store" });
      const d = await r.json();
      if (fechaElegida !== fecha) return; // el usuario ya eligió otro día
      if (!d.ok) throw new Error(d.error);
      const libres = d.horas.filter((h) => h.libre).length;
      cont.innerHTML = !d.horas.length || !libres
        ? '<p class="estado-carga">No quedan horas disponibles este día. Prueba con otro.</p>'
        : `<div class="horas-disponibles" role="group" aria-label="Elige una hora">${d.horas.map((h) =>
            `<button type="button" class="hora" data-hora="${h.hora}" aria-pressed="false" ${h.libre ? "" : "disabled"}>${h.hora}</button>`).join("")}</div>`;
    } catch (e) {
      cont.innerHTML = '<p class="estado-carga">No pudimos cargar las horas. Revisa tu conexión e inténtalo otra vez.</p>';
    }
  }

  function elegirHora(hora) {
    horaElegida = hora;
    document.querySelectorAll("[data-hora]").forEach((b) => b.setAttribute("aria-pressed", String(b.dataset.hora === hora)));
    pintarResumen();
  }

  function validar() {
    const f = new FormData(form);
    if ((f.get("nombre") || "").trim().length < 2) return ["nombre", "Escribe tu nombre."];
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test((f.get("email") || "").trim())) return ["email", "Escribe un correo válido."];
    if ((f.get("telefono") || "").replace(/\D/g, "").length < 8) return ["telefono", "Escribe un teléfono válido."];
    if (pedido.modalidad === "domicilio") {
      if (!f.get("comuna")) return ["comuna", "Elige tu comuna."];
      if ((f.get("direccion") || "").trim().length < 4) return ["direccion", "Escribe tu dirección."];
    }
    if (!fechaElegida || !horaElegida) return [null, "Elige el día y la hora de tu primera sesión."];
    if (!f.get("acepta")) return ["acepta", "Debes aceptar las condiciones del servicio."];
    return null;
  }

  async function enviar(e) {
    e.preventDefault();
    if (enviando) return;
    const problema = validar();
    if (problema) {
      mostrarError(problema[1]);
      if (problema[0]) form.elements[problema[0]].focus({ preventScroll: true });
      return;
    }
    mostrarError("");
    const f = new FormData(form);
    const boton = $("[data-enviar]");
    enviando = true;
    boton.disabled = true;
    boton.textContent = "Procesando…";
    try {
      const r = await fetch("api/crear-pedido.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          items: pedido.lineas.map((l) => ({ id: l.id, cantidad: l.cantidad })),
          modalidad: pedido.modalidad,
          fecha: fechaElegida,
          hora: horaElegida,
          metodo: pedido.total === 0 ? "gratis" : f.get("metodo"),
          acepta: !!f.get("acepta"),
          website: f.get("website"),
          cliente: {
            nombre: f.get("nombre"), email: f.get("email"), telefono: f.get("telefono"),
            personas: f.get("personas"), objetivo: f.get("objetivo"),
            comuna: f.get("comuna"), direccion: f.get("direccion"), comentarios: f.get("comentarios"),
          },
        }),
      });
      const d = await r.json().catch(() => ({ ok: false, error: "Error inesperado. Inténtalo otra vez." }));
      if (!d.ok) {
        if (r.status === 409) cargarHoras(fechaElegida);
        throw new Error(d.error);
      }
      vaciar();
      location.href = d.redirigir;
    } catch (err) {
      mostrarError(err.message || "No pudimos procesar tu reserva. Inténtalo otra vez.");
      enviando = false;
      boton.disabled = false;
      boton.textContent = textoBoton();
    }
  }

  const textoBoton = () => pedido.total === 0 ? "Reservar clase de prueba" : `Ir a pagar ${pesos(pedido.total)}`;

  function actualizarBotonPago() {
    if (pedido.total === 0) return;
    const metodo = new FormData(form).get("metodo");
    $("[data-enviar]").textContent = metodo === "transferencia" ? `Reservar y pagar por transferencia` : textoBoton();
  }

  document.addEventListener("DOMContentLoaded", async () => {
    pedido = await detalle().catch(() => ({ lineas: [] }));
    if (!pedido.lineas.length) { $("[data-vacio]").hidden = false; return; }
    form.hidden = false;

    $("[data-comunas]").insertAdjacentHTML("beforeend", COMUNAS.map((c) => `<option>${c}</option>`).join(""));
    $("[data-solo-domicilio]").hidden = pedido.modalidad !== "domicilio";
    $("[data-texto-lugar]").textContent = pedido.modalidad === "sede"
      ? "Sede San Joaquín: lunes a viernes a las 10:00, 11:00, 20:00, 21:00 y 22:00."
      : "A domicilio: lunes a viernes de 6:30 a 21:00 y sábado de 7:00 a 12:00 (la última clase empieza una hora antes).";
    if (pedido.total === 0) $("[data-paso-pago]").hidden = true;
    $("[data-enviar]").textContent = textoBoton();
    pintarResumen();

    try {
      const r = await fetch("api/opciones.php", { cache: "no-store" });
      opciones = await r.json();
      if (!opciones.ok) throw new Error();
    } catch (e) {
      $("[data-dias]").innerHTML = "";
      $("[data-horas]").innerHTML = '<p class="estado-carga">La agenda no está disponible en este momento. Escríbenos por WhatsApp para reservar.</p>';
      return;
    }
    if (opciones.transferencia) {
      $("[data-opcion-transferencia]").hidden = false;
      $("[data-texto-transferencia]").textContent = `Te enviamos los datos por correo. Guardamos tu horario por ${opciones.horas_transferencia} horas.`;
    }
    pintarDias();
  });

  document.addEventListener("click", (e) => {
    const b = e.target.closest("button");
    if (!b) return;
    if (b.dataset.fecha) cargarHoras(b.dataset.fecha);
    if (b.dataset.hora && !b.disabled) elegirHora(b.dataset.hora);
  });
  form.addEventListener("change", (e) => { if (e.target.name === "metodo") actualizarBotonPago(); });
  form.addEventListener("submit", enviar);
})();
