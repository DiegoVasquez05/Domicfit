/* Página de resultado: muestra el estado del pedido después de reservar o pagar. */
(function () {
  "use strict";
  const { pesos, esc, whatsappUrl } = window.Domicfit;
  const cont = document.querySelector("[data-resultado]");
  const params = new URLSearchParams(location.search);
  const ref = (params.get("ref") || "").toUpperCase();
  const paymentId = params.get("payment_id") || params.get("collection_id") || "";
  let intentos = 0;

  function pintar({ icono, titulo, texto, p, extra = "", botones = "" }) {
    const lugar = p.modalidad === "sede" ? "Sede San Joaquín, Pasaje Río Valdivia 3705B" : "A domicilio";
    cont.innerHTML = `
      <div class="estado-icono" aria-hidden="true">${icono}</div>
      <h1>${titulo}</h1>
      <p>${texto}</p>
      <div class="tarjeta-datos">
        ${p.items.map((i) => `<div class="resumen__item"><span>${esc(i.nombre)}${i.cantidad > 1 ? " x" + i.cantidad : ""}</span><span>${i.precio === 0 ? "Gratis" : pesos(i.precio * i.cantidad)}</span></div>`).join("")}
        <div class="resumen__item"><span>Primera sesión</span><span>${esc(p.inicio_texto)}</span></div>
        <div class="resumen__item"><span>Lugar</span><span>${lugar}</span></div>
        <div class="resumen__item"><span>N° de pedido</span><span>${esc(p.ref)}</span></div>
        ${extra}
      </div>
      <div class="botones">${botones}
        <a class="boton boton--borde" href="${whatsappUrl(`Hola Domicfit, tengo una consulta sobre mi pedido ${p.ref}.`)}" target="_blank" rel="noopener">Escribir por WhatsApp</a>
        <a class="boton boton--borde" href="./">Volver al inicio</a>
      </div>`;
  }

  async function consultar() {
    if (!/^[A-F0-9]{16}$/.test(ref)) {
      cont.innerHTML = '<h1>Pedido no encontrado</h1><p>Revisa el enlace o escríbenos por WhatsApp.</p><div class="botones"><a class="boton" href="./">Volver al inicio</a></div>';
      return;
    }
    let p;
    try {
      const r = await fetch(`api/estado-pedido.php?ref=${ref}${paymentId ? "&payment_id=" + encodeURIComponent(paymentId) : ""}`, { cache: "no-store" });
      p = await r.json();
      if (!p.ok) throw new Error(p.error);
    } catch (e) {
      cont.innerHTML = `<h1>No pudimos consultar tu pedido</h1><p>${esc(e.message || "Inténtalo de nuevo en unos minutos.")}</p><div class="botones"><a class="boton" href="./">Volver al inicio</a></div>`;
      return;
    }

    if (p.estado === "pagado") {
      pintar({ icono: "✓", titulo: `¡Listo, ${esc(p.nombre)}!`, p,
        texto: "Tu pago fue recibido y tu primera sesión está agendada. Te enviamos la confirmación y las condiciones por correo. Las siguientes sesiones las coordinamos contigo por WhatsApp." });
    } else if (p.estado === "reservado") {
      pintar({ icono: "✓", titulo: "Clase de prueba reservada", p,
        texto: `¡Te esperamos, ${esc(p.nombre)}! Te enviamos la confirmación por correo.` });
    } else if (p.estado === "pendiente_transferencia") {
      const t = p.transferencia;
      pintar({ icono: "$", titulo: "Falta tu transferencia", p,
        texto: `Guardamos tu horario hasta el ${esc(p.expira)}. Transfiere ${pesos(p.total)} y envíanos el comprobante por WhatsApp indicando tu número de pedido. También te enviamos estos datos por correo.`,
        extra: `<hr style="border-color:var(--borde);width:100%">
          <div class="resumen__item"><span>Titular</span><span>${esc(t.titular)}</span></div>
          <div class="resumen__item"><span>RUT</span><span>${esc(t.rut)}</span></div>
          <div class="resumen__item"><span>Banco</span><span>${esc(t.banco)}</span></div>
          <div class="resumen__item"><span>Tipo de cuenta</span><span>${esc(t.tipo)}</span></div>
          <div class="resumen__item"><span>Número</span><span>${esc(t.numero)}</span></div>
          <div class="resumen__item"><span>Correo</span><span>${esc(t.correo)}</span></div>
          <div class="total"><span>Monto</span><b>${pesos(p.total)}</b></div>`,
        botones: `<a class="boton" href="${whatsappUrl(`Hola Domicfit, envío el comprobante de transferencia del pedido ${p.ref} por ${pesos(p.total)}.`)}" target="_blank" rel="noopener">Enviar comprobante</a>` });
    } else if (p.estado === "pendiente_pago") {
      // Mercado Pago puede tardar unos segundos en confirmar: se vuelve a consultar.
      if (paymentId && intentos < 5) {
        intentos++;
        cont.innerHTML = '<p class="estado-carga">Confirmando tu pago con Mercado Pago…</p>';
        setTimeout(consultar, 3000);
        return;
      }
      pintar({ icono: "!", titulo: "Tu pago no se completó", p,
        texto: "Todavía no recibimos la confirmación del pago. Si pagaste en efectivo o el banco lo está revisando, te avisaremos por correo cuando se apruebe. Si no alcanzaste a pagar, puedes intentarlo de nuevo.",
        botones: p.link_pago ? `<a class="boton" href="${esc(p.link_pago)}">Intentar pagar de nuevo</a>` : "" });
    } else if (p.estado === "vencido") {
      pintar({ icono: "!", titulo: "La reserva venció", p,
        texto: "El tiempo para pagar terminó y el horario quedó libre. Puedes hacer una nueva reserva o escribirnos por WhatsApp.",
        botones: '<a class="boton" href="./#planes">Reservar de nuevo</a>' });
    } else {
      pintar({ icono: "✕", titulo: "Reserva cancelada", p,
        texto: "Esta reserva fue cancelada. Si crees que es un error, escríbenos por WhatsApp.",
        botones: '<a class="boton" href="./#planes">Ver planes</a>' });
    }
  }

  document.addEventListener("DOMContentLoaded", consultar);
})();
