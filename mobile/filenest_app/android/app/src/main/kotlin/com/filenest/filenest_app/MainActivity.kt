package com.filenest.filenest_app

import android.graphics.Bitmap
import android.graphics.Color
import android.graphics.pdf.PdfRenderer
import android.os.Handler
import android.os.Looper
import android.os.ParcelFileDescriptor
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.io.ByteArrayOutputStream
import java.io.File
import java.util.concurrent.Executors

/**
 * «قارئ PDF» inside the app (2026-11-11, by request): Android's own PdfRenderer draws the
 * pages, so a downloaded PDF opens without another app. One document at a time; every call
 * runs on one background thread (PdfRenderer is not thread-safe) and answers on the main one.
 */
class MainActivity : FlutterActivity() {
    private val worker = Executors.newSingleThreadExecutor()
    private val main = Handler(Looper.getMainLooper())
    private var descriptor: ParcelFileDescriptor? = null
    private var renderer: PdfRenderer? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "filenest/pdf").setMethodCallHandler { call, result ->
            worker.execute {
                try {
                    val answer: Any? = when (call.method) {
                        "open" -> open(call.argument<String>("path")!!)
                        "render" -> render(call.argument<Int>("page")!!, call.argument<Int>("width")!!)
                        "close" -> { close(); null }
                        else -> throw UnsupportedOperationException(call.method)
                    }
                    main.post { result.success(answer) }
                } catch (e: SecurityException) {
                    main.post { result.error("password", "الملف محمي بكلمة سر.", null) }
                } catch (e: UnsupportedOperationException) {
                    main.post { result.notImplemented() }
                } catch (e: Throwable) {
                    main.post { result.error("pdf", e.message ?: e.javaClass.simpleName, null) }
                }
            }
        }
    }

    private fun open(path: String): Map<String, Any> {
        close()
        val fd = ParcelFileDescriptor.open(File(path), ParcelFileDescriptor.MODE_READ_ONLY)
        descriptor = fd
        val r = PdfRenderer(fd)
        renderer = r
        val sizes = ArrayList<List<Int>>(r.pageCount)
        for (i in 0 until r.pageCount) {
            val page = r.openPage(i)
            sizes.add(listOf(page.width, page.height))
            page.close()
        }
        return mapOf("count" to r.pageCount, "sizes" to sizes)
    }

    private fun render(index: Int, width: Int): ByteArray {
        val r = renderer ?: throw IllegalStateException("no document")
        val page = r.openPage(index)
        try {
            val w = width.coerceIn(200, 2400)
            val h = (w.toDouble() * page.height / page.width).toInt().coerceAtLeast(1)
            val bitmap = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
            bitmap.eraseColor(Color.WHITE)
            page.render(bitmap, null, null, PdfRenderer.Page.RENDER_MODE_FOR_DISPLAY)
            val out = ByteArrayOutputStream()
            bitmap.compress(Bitmap.CompressFormat.JPEG, 88, out)
            bitmap.recycle()
            return out.toByteArray()
        } finally {
            page.close()
        }
    }

    private fun close() {
        try { renderer?.close() } catch (_: Throwable) {}
        try { descriptor?.close() } catch (_: Throwable) {}
        renderer = null
        descriptor = null
    }

    override fun onDestroy() {
        worker.execute { close() }
        worker.shutdown()
        super.onDestroy()
    }
}
