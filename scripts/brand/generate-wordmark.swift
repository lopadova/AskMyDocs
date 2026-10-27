import AppKit
import CoreText

// Run from the repository root: swift scripts/brand/generate-wordmark.swift
// The original app artwork stays unchanged; only the wordmark is outlined.
let root = URL(fileURLWithPath: FileManager.default.currentDirectoryPath)
let input = root.appendingPathComponent("desktop/src-tauri/icons/128x128@2x.png")
let output = root.appendingPathComponent("public/brand")
try FileManager.default.createDirectory(at: output, withIntermediateDirectories: true)
let markData = try Data(contentsOf: input)
let masterData = try Data(contentsOf: root.appendingPathComponent("desktop/src-tauri/icons/icon-master.png"))
let mark = NSImage(data: masterData)!.cgImage(forProposedRect: nil, context: nil, hints: nil)!
let text = "AskMyDocs"
let font = CTFontCreateWithName("Georgia" as CFString, 76, nil)
let attributes: [NSAttributedString.Key: Any] = [
    NSAttributedString.Key(kCTFontAttributeName as String): font,
    NSAttributedString.Key(kCTKernAttributeName as String): -2.0
]
let line = CTLineCreateWithAttributedString(NSAttributedString(string: text, attributes: attributes))
var ascent: CGFloat = 0, descent: CGFloat = 0, leading: CGFloat = 0
let textWidth = CGFloat(CTLineGetTypographicBounds(line, &ascent, &descent, &leading))
let height: CGFloat = 160
let width = ceil(156 + textWidth + 16)
let baseline = (height - ascent + descent) / 2
let runs = CTLineGetGlyphRuns(line) as! [CTRun]
let textPath = CGMutablePath()
for run in runs {
    let count = CTRunGetGlyphCount(run)
    var glyphs = [CGGlyph](repeating: 0, count: count)
    var positions = [CGPoint](repeating: .zero, count: count)
    CTRunGetGlyphs(run, CFRange(location: 0, length: 0), &glyphs)
    CTRunGetPositions(run, CFRange(location: 0, length: 0), &positions)
    let runFont = (CTRunGetAttributes(run) as NSDictionary)[kCTFontAttributeName] as! CTFont
    for index in 0..<count {
        if let path = CTFontCreatePathForGlyph(runFont, glyphs[index], nil) {
            let transform = CGAffineTransform(translationX: 156 + positions[index].x, y: baseline + positions[index].y)
            textPath.addPath(path, transform: transform)
        }
    }
}
func number(_ value: CGFloat) -> String { String(format: "%.3f", Double(value)) }
var svgPath = ""
textPath.applyWithBlock { element in
    let item = element.pointee
    func point(_ i: Int) -> String { "\(number(item.points[i].x)) \(number(height - item.points[i].y))" }
    switch item.type {
    case .moveToPoint: svgPath += "M\(point(0))"
    case .addLineToPoint: svgPath += "L\(point(0))"
    case .addQuadCurveToPoint: svgPath += "Q\(point(0)) \(point(1))"
    case .addCurveToPoint: svgPath += "C\(point(0)) \(point(1)) \(point(2))"
    case .closeSubpath: svgPath += "Z"
    @unknown default: break
    }
}
let iconRect = CGRect(x: 16, y: 24, width: 112, height: 112)
func color(_ hex: String) -> CGColor {
    let value = UInt32(hex, radix: 16)!
    return CGColor(red: CGFloat((value >> 16) & 255) / 255, green: CGFloat((value >> 8) & 255) / 255, blue: CGFloat(value & 255) / 255, alpha: 1)
}
func drawLogo(_ context: CGContext, ink: String) {
    context.saveGState()
    context.addPath(CGPath(roundedRect: iconRect, cornerWidth: 24, cornerHeight: 24, transform: nil))
    context.clip()
    context.draw(mark, in: iconRect)
    context.restoreGState()
    context.addPath(textPath)
    context.setFillColor(color(ink))
    context.fillPath()
}
func writePNG(_ context: CGContext, to destination: URL) throws {
    let image = context.makeImage()!
    let rep = NSBitmapImageRep(cgImage: image)
    try rep.representation(using: .png, properties: [:])!.write(to: destination)
}
for (name, ink) in [("light", "292A2C"), ("dark", "F2F0EB")] {
    let svg = """
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 \(Int(width)) 160" role="img" aria-labelledby="title">
      <title id="title">\(text)</title>
      <defs><clipPath id="mark"><rect x="16" y="24" width="112" height="112" rx="24"/></clipPath></defs>
      <image x="16" y="24" width="112" height="112" clip-path="url(#mark)" href="data:image/png;base64,\(markData.base64EncodedString())"/>
      <path fill="#\(ink)" d="\(svgPath)"/>
    </svg>
    """
    try svg.write(to: output.appendingPathComponent("askmydocs-wordmark-\(name).svg"), atomically: true, encoding: .utf8)
    let scale: CGFloat = 4
    let context = CGContext(data: nil, width: Int(width * scale), height: Int(height * scale), bitsPerComponent: 8, bytesPerRow: 0, space: CGColorSpaceCreateDeviceRGB(), bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue)!
    context.scaleBy(x: scale, y: scale)
    drawLogo(context, ink: ink)
    try writePNG(context, to: output.appendingPathComponent("askmydocs-wordmark-\(name).png"))
}
try markData.write(to: output.appendingPathComponent("askmydocs-mark.png"))
let preview = CGContext(data: nil, width: Int(width * 2), height: 640, bitsPerComponent: 8, bytesPerRow: 0, space: CGColorSpaceCreateDeviceRGB(), bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue)!
preview.scaleBy(x: 2, y: 2)
preview.setFillColor(color("F4F2ED")); preview.fill(CGRect(x: 0, y: 160, width: width, height: 160))
preview.setFillColor(color("202124")); preview.fill(CGRect(x: 0, y: 0, width: width, height: 160))
drawLogo(preview, ink: "F2F0EB")
preview.translateBy(x: 0, y: 160)
drawLogo(preview, ink: "292A2C")
try writePNG(preview, to: root.appendingPathComponent("docs/brand/wordmark-preview.png"))
print("Generated \(Int(width))×160 SVG wordmarks, 4× transparent PNGs, and light/dark preview.")

let markSVG = """
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><defs><clipPath id="mark"><rect width="256" height="256" rx="55"/></clipPath></defs><image width="256" height="256" clip-path="url(#mark)" href="data:image/png;base64,\(markData.base64EncodedString())"/></svg>
"""
try markSVG.write(to: root.appendingPathComponent("public/askmydocs-logo.svg"), atomically: true, encoding: .utf8)
try markSVG.write(to: root.appendingPathComponent("public/landing/favicon.svg"), atomically: true, encoding: .utf8)
try Data(contentsOf: root.appendingPathComponent("desktop/src-tauri/icons/32x32.png")).write(to: output.appendingPathComponent("favicon.png"))
